<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EduHubAccounts;
use App\Services\ProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Signed server-to-server API for MbunieEduHub (see VerifyPartnerSignature):
 * EduHub sells VPN plans with its own verified ClickPesa payments, then
 * activates them here; its admin dashboard reads the VPN stats.
 */
class PartnerController extends Controller
{
    /** Headline numbers + recent activity for the EduHub admin dashboard. */
    public function stats(): JsonResponse
    {
        $activeSubs = Subscription::where('status', 'active')->where('expires_at', '>', now());

        $revenue = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', now()->startOfMonth())
            ->selectRaw('currency, SUM(amount_cents) as cents, COUNT(*) as n')
            ->groupBy('currency')->get()
            ->mapWithKeys(fn ($r) => [$r->currency => ['cents' => (int) $r->cents, 'count' => (int) $r->n]]);

        $online = Device::online()->whereNull('revoked_at')->with('subscription.user')->latest('last_seen_at')->get();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'customers' => User::whereHas('subscriptions')->count(),
            'subscriptions' => [
                'active' => (clone $activeSubs)->count(),
                'expiring_3d' => (clone $activeSubs)->where('expires_at', '<=', now()->addDays(3))->count(),
                'expired' => Subscription::where(fn ($q) => $q->where('status', 'expired')
                    ->orWhere(fn ($q) => $q->where('status', 'active')->where('expires_at', '<=', now())))->count(),
                'suspended' => Subscription::where('status', 'suspended')->count(),
            ],
            'payments' => [
                'pending' => Invoice::whereIn('status', ['pending', 'pending_review'])->count(),
                'failed_30d' => Invoice::where('status', 'failed')->where('updated_at', '>=', now()->subDays(30))->count(),
                'revenue_this_month' => $revenue,
            ],
            'online_now' => [
                'devices' => $online->count(),
                'customers' => $online->pluck('subscription_id')->unique()->count(),
                'list' => $online->take(50)->map(fn (Device $d) => [
                    'customer' => $d->subscription?->user?->email ?? $d->subscription?->user?->phone,
                    'eduhub_user_id' => $d->subscription?->user?->eduhub_user_id,
                    'platform' => $d->platform,
                    'protocol' => $d->protocol,
                    'connected_since' => $d->connected_at?->toIso8601String(),
                    'last_seen' => $d->last_seen_at?->toIso8601String(),
                ])->values(),
            ],
            'nodes' => Node::orderBy('priority')->get()->map(fn (Node $n) => [
                'name' => $n->name,
                'status' => $n->status,
                'last_health_at' => $n->last_health_at?->toIso8601String(),
            ]),
            'recent_payments' => Invoice::with('user')->latest()->take(15)->get()->map(fn (Invoice $i) => [
                'id' => $i->id,
                'customer' => $i->user?->email ?? $i->user?->phone,
                'plan' => $i->plan_code,
                'amount_cents' => $i->amount_cents,
                'currency' => $i->currency,
                'provider' => $i->provider,
                'status' => $i->status,
                'paid_at' => $i->paid_at?->toIso8601String(),
                'created_at' => $i->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /** One EduHub customer's VPN status (for their EduHub dashboard). */
    public function customer(int $eduhubUserId): JsonResponse
    {
        $user = User::where('eduhub_user_id', $eduhubUserId)->first();
        if (! $user) {
            return response()->json(['linked' => false, 'subscription' => null]);
        }

        $sub = $user->subscriptions()->latest('expires_at')->first();
        $active = $sub && $sub->status === 'active' && $sub->expires_at?->isFuture();

        return response()->json([
            'linked' => true,
            'subscription' => $sub ? [
                'plan' => $sub->plan_code,
                'status' => $active ? 'active' : ($sub->expires_at?->isPast() ? 'expired' : $sub->status),
                'started_at' => $sub->started_at?->toIso8601String(),
                'expires_at' => $sub->expires_at?->toIso8601String(),
                'max_devices' => $sub->max_devices,
                'devices' => $sub->devices()->whereNull('revoked_at')->count(),
                'online_now' => $sub->devices()->online()->whereNull('revoked_at')->exists(),
            ] : null,
            'payments' => $user->invoices()->latest()->take(20)->get()->map(fn (Invoice $i) => [
                'plan' => $i->plan_code,
                'amount_cents' => $i->amount_cents,
                'currency' => $i->currency,
                'provider' => $i->provider,
                'status' => $i->status,
                'paid_at' => $i->paid_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * EduHub verified a payment (its own ClickPesa webhook) and asks us to
     * activate / extend the plan. Idempotent by `reference`.
     */
    public function activate(Request $request, EduHubAccounts $accounts, ProvisioningService $provisioner): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            'eduhub_user_id' => ['required', 'integer', 'min:1'],
            'email' => ['required', 'email', 'max:120'],
            'name' => ['nullable', 'string', 'max:120'],
            'email_verified' => ['required', 'boolean'],
            'plan_code' => ['required', 'string', 'max:40'],
            'amount_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'in:tzs,usd'],
        ]);

        $key = 'eduhub:' . $data['reference'];
        if ($done = Invoice::where('idempotency_key', $key)->first()) {
            return $this->activationResponse($done, duplicate: true);
        }

        $plan = Plan::where('code', $data['plan_code'])->where('is_active', true)->first();
        if (! $plan) {
            return response()->json(['error' => 'unknown_plan'], 422);
        }

        // The amount EduHub collected must cover our price for this plan.
        $price = $plan->priceCents($data['currency']);
        if ($price <= 0 || $data['amount_cents'] < $price) {
            AuditLog::create([
                'actor' => 'eduhub:partner',
                'action' => 'activation.amount_mismatch',
                'target' => "plan:{$plan->code}",
                'meta' => ['reference' => $data['reference'], 'expected' => $price, 'got' => $data['amount_cents'], 'currency' => $data['currency']],
            ]);

            return response()->json(['error' => 'amount_mismatch', 'expected_cents' => $price], 422);
        }

        try {
            $user = $accounts->resolve([
                'id' => $data['eduhub_user_id'],
                'name' => $data['name'] ?? null,
                'email' => strtolower($data['email']),
                'email_verified_at' => $data['email_verified'] ? now()->toIso8601String() : null,
            ], 'partner');
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        $invoice = DB::transaction(function () use ($user, $plan, $data, $key) {
            return Invoice::create([
                'user_id' => $user->id,
                'plan_code' => $plan->code,
                'provider' => 'eduhub',
                'payment_method' => 'clickpesa',
                'currency' => $data['currency'],
                'amount_cents' => $data['amount_cents'],
                'status' => 'paid',
                'provider_ref' => $data['reference'],
                'idempotency_key' => $key,
                'paid_at' => now(),
                'meta' => ['source' => 'eduhub'],
            ]);
        });

        $sub = $provisioner->fulfill($invoice);

        AuditLog::create([
            'actor' => 'eduhub:partner',
            'action' => 'subscription.provisioned',
            'target' => "subscription:{$sub->id}",
            'meta' => ['invoice_id' => $invoice->id, 'reference' => $data['reference'], 'plan' => $plan->code],
        ]);

        return $this->activationResponse($invoice->refresh(), duplicate: false);
    }

    private function activationResponse(Invoice $invoice, bool $duplicate): JsonResponse
    {
        $sub = $invoice->subscription_id ? Subscription::find($invoice->subscription_id) : null;

        return response()->json([
            'duplicate' => $duplicate,
            'invoice_id' => $invoice->id,
            'status' => $invoice->status,
            'subscription' => $sub ? [
                'plan' => $sub->plan_code,
                'status' => $sub->status,
                'expires_at' => $sub->expires_at?->toIso8601String(),
            ] : null,
        ], $duplicate ? 200 : 201);
    }
}
