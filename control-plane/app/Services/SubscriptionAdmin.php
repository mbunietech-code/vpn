<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Peer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Manual, audited admin actions on subscriptions. Every change that affects
 * VPN access also updates the peers and bumps the node so the agent applies
 * it within ~15 seconds.
 */
class SubscriptionAdmin
{
    public function __construct(private ProvisioningService $provisioner) {}

    /** Add days (from now if already expired) and make sure access is on. */
    public function extend(Subscription $sub, int $days, string $actor, ?string $note = null): Subscription
    {
        if ($days < 1 || $days > 3650) {
            throw new InvalidArgumentException('Days must be between 1 and 3650.');
        }

        return DB::transaction(function () use ($sub, $days, $actor, $note) {
            $from = $sub->expires_at && $sub->expires_at->isFuture() ? $sub->expires_at : now();
            $old = $sub->expires_at?->toIso8601String();
            $sub->update([
                'expires_at' => $from->copy()->addDays($days),
                'started_at' => $sub->started_at ?? now(),
                'status' => $sub->status === 'suspended' ? 'suspended' : 'active',
            ]);
            if ($sub->status === 'active') {
                $this->provisioner->syncPeers($sub->refresh());
            }
            $this->audit($actor, 'subscription.extended', $sub, ['days' => $days, 'from' => $old, 'to' => $sub->expires_at->toIso8601String(), 'note' => $note]);

            return $sub->refresh();
        });
    }

    /** Cut VPN access now (peers disabled on every node). */
    public function suspend(Subscription $sub, string $actor, ?string $reason = null): Subscription
    {
        return DB::transaction(function () use ($sub, $actor, $reason) {
            $sub->update(['status' => 'suspended']);
            $this->disablePeers($sub);
            $this->audit($actor, 'subscription.suspended', $sub, ['reason' => $reason]);

            return $sub->refresh();
        });
    }

    /** Lift a suspension; access comes back only if time is left. */
    public function restore(Subscription $sub, string $actor): Subscription
    {
        return DB::transaction(function () use ($sub, $actor) {
            $live = $sub->expires_at && $sub->expires_at->isFuture();
            $sub->update(['status' => $live ? 'active' : 'expired']);
            if ($live) {
                $this->provisioner->syncPeers($sub->refresh());
            }
            $this->audit($actor, 'subscription.restored', $sub, ['access' => $live]);

            return $sub->refresh();
        });
    }

    /**
     * Give a customer a plan without a payment (gift, compensation, test).
     * Recorded as a zero-amount "admin" invoice so history stays complete.
     */
    public function grant(User $user, Plan $plan, string $actor, ?string $note = null): Subscription
    {
        $invoice = Invoice::create([
            'user_id' => $user->id,
            'plan_code' => $plan->code,
            'provider' => 'admin',
            'payment_method' => 'manual-grant',
            'currency' => 'usd',
            'amount_cents' => 0,
            'status' => 'paid',
            'idempotency_key' => 'admin:'.Str::uuid(),
            'paid_at' => now(),
            'meta' => ['granted_by' => $actor, 'note' => $note],
        ]);

        $sub = $this->provisioner->fulfill($invoice);
        $this->audit($actor, 'subscription.granted', $sub, ['plan' => $plan->code, 'invoice_id' => $invoice->id, 'note' => $note]);

        return $sub;
    }

    /**
     * Delete a subscription for good: its VPN keys (peers) and devices go with
     * it, so its VPN stops within about 15 seconds. Payments stay, unlinked.
     */
    public function delete(Subscription $sub, string $actor): void
    {
        DB::transaction(function () use ($sub, $actor) {
            $nodeIds = $sub->peers()->pluck('node_id')->unique();
            $this->audit($actor, 'subscription.deleted', $sub, [
                'user_id' => $sub->user_id,
                'plan' => $sub->plan_code,
                'status' => $sub->status,
                'expires_at' => $sub->expires_at?->toIso8601String(),
            ]);
            $sub->delete();
            Node::whereIn('id', $nodeIds)->get()->each->bumpPeerVersion();
        });
    }

    /**
     * Delete a customer and everything of theirs: subscriptions, VPN keys,
     * devices, payments (and screenshots), app sessions. Admins can't be
     * deleted here. If they buy again later they start as a new customer.
     */
    public function deleteCustomer(User $user, string $actor): bool
    {
        if ($user->is_admin) {
            return false;
        }

        DB::transaction(function () use ($user, $actor) {
            $subIds = $user->subscriptions()->pluck('id');
            $nodeIds = Peer::whereIn('subscription_id', $subIds)->pluck('node_id')->unique();
            $proofs = $user->invoices()->whereNotNull('proof_path')->pluck('proof_path');

            AuditLog::create([
                'actor' => $actor,
                'action' => 'user.deleted',
                'target' => "user:{$user->id}",
                'meta' => array_filter([
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'eduhub_user_id' => $user->eduhub_user_id,
                    'subscriptions' => $subIds->count(),
                    'invoices' => $user->invoices()->count(),
                ]),
            ]);

            $user->tokens()->delete();
            $user->delete(); // subscriptions, peers, devices and invoices cascade
            Node::whereIn('id', $nodeIds)->get()->each->bumpPeerVersion();
            DB::afterCommit(fn () => Storage::delete($proofs->all()));
        });

        return true;
    }

    /** Suspend or restore every subscription of a customer. */
    public function setUserStatus(User $user, string $status, string $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($user, $status, $actor, $reason) {
            $user->forceFill(['status' => $status])->save();
            foreach ($user->subscriptions as $sub) {
                if ($status === 'suspended' && $sub->status === 'active') {
                    $this->suspend($sub, $actor, $reason ?? 'customer suspended');
                } elseif ($status === 'active' && $sub->status === 'suspended') {
                    $this->restore($sub, $actor);
                }
            }
            // Revoke app sessions of a suspended customer.
            if ($status === 'suspended') {
                $user->tokens()->delete();
            }
            AuditLog::create([
                'actor' => $actor,
                'action' => "user.$status",
                'target' => "user:{$user->id}",
                'meta' => ['reason' => $reason],
            ]);
        });
    }

    private function disablePeers(Subscription $sub): void
    {
        $nodeIds = $sub->peers()->where('status', 'active')->pluck('node_id')->unique();
        $sub->peers()->where('status', 'active')->update(['status' => 'disabled']);
        Node::whereIn('id', $nodeIds)->get()->each->bumpPeerVersion();
    }

    private function audit(string $actor, string $action, Subscription $sub, array $meta): void
    {
        AuditLog::create([
            'actor' => $actor,
            'action' => $action,
            'target' => "subscription:{$sub->id}",
            'meta' => array_filter($meta, fn ($v) => $v !== null),
        ]);
    }
}
