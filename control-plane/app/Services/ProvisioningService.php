<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Node;
use App\Models\Peer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a PAID invoice into an ACTIVE subscription with peers on every node
 * the plan grants - with no human in the loop.
 * See 05-Addendum-MVPN.md §A2.
 */
class ProvisioningService
{
    /** Activate (or extend) the subscription behind a paid invoice. */
    public function fulfill(Invoice $invoice): Subscription
    {
        return DB::transaction(function () use ($invoice) {
            $plan = Plan::where('code', $invoice->plan_code)->firstOrFail();
            $user = $invoice->user;

            $sub = $this->upsertSubscription($user, $plan);
            $this->syncPeers($sub, $plan);

            $sub->update(['status' => 'active', 'last_synced_at' => now()]);

            return $sub->refresh();
        });
    }

    private function upsertSubscription(User $user, Plan $plan): Subscription
    {
        $existing = $user->subscriptions()
            ->where('plan_code', $plan->code)
            ->whereIn('status', ['active', 'pending', 'expired'])
            ->latest('expires_at')
            ->first();

        $base = $existing && $existing->expires_at && $existing->expires_at->isFuture()
            ? $existing->expires_at
            : now();

        if ($existing) {
            $existing->update([
                'expires_at' => $base->copy()->addDays($plan->days),
                'started_at' => $existing->started_at ?? now(),
                'max_devices' => $plan->max_devices,
            ]);

            return $existing;
        }

        return $user->subscriptions()->create([
            'plan_code' => $plan->code,
            'status' => 'pending',
            'sub_token' => Str::random(48),
            'max_devices' => $plan->max_devices,
            'started_at' => now(),
            'expires_at' => now()->addDays($plan->days),
        ]);
    }

    /** Ensure the subscription has an active peer on every eligible node. */
    public function syncPeers(Subscription $sub, ?Plan $plan = null): void
    {
        $plan ??= $sub->plan();
        $nodes = $this->eligibleNodes($plan);

        foreach ($nodes as $node) {
            foreach (['vless-reality', 'hysteria2'] as $protocol) {
                $peer = Peer::firstOrNew([
                    'subscription_id' => $sub->id,
                    'node_id' => $node->id,
                    'protocol' => $protocol,
                ]);

                if (! $peer->exists) {
                    $peer->remote_id = (string) Str::uuid();
                    $peer->secret = $protocol === 'hysteria2' ? Str::random(24) : null;
                }

                $peer->status = 'active';
                $peer->save();
            }

            $node->bumpPeerVersion();
        }

        // Disable peers on nodes no longer in scope.
        $keepNodeIds = $nodes->pluck('id');
        Peer::where('subscription_id', $sub->id)
            ->whereNotIn('node_id', $keepNodeIds)
            ->where('status', 'active')
            ->get()
            ->each(function (Peer $peer) {
                $peer->update(['status' => 'disabled']);
                $peer->node?->bumpPeerVersion();
            });
    }

    /** Suspend a subscription and disable all its peers immediately. */
    public function suspend(Subscription $sub, string $reason = ''): void
    {
        DB::transaction(function () use ($sub) {
            $sub->update(['status' => 'suspended']);
            $sub->peers()->where('status', 'active')->get()->each(function (Peer $peer) {
                $peer->update(['status' => 'disabled']);
                $peer->node?->bumpPeerVersion();
            });
        });
    }

    /** @return \Illuminate\Support\Collection<int, Node> */
    private function eligibleNodes(?Plan $plan)
    {
        $q = Node::query()->whereIn('status', ['online', 'degraded']);

        $scope = $plan?->node_scope;
        if (is_array($scope) && $scope !== []) {
            $regions = collect($scope)
                ->filter(fn ($s) => str_starts_with($s, 'region:'))
                ->map(fn ($s) => Str::after($s, 'region:'))
                ->all();
            if ($regions) {
                $q->whereIn('region', $regions);
            }
        }

        return $q->get()->filter->hasCapacity()->values();
    }
}
