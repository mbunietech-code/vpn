<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One free trial per customer, given automatically on their first sign-in.
 * A phone that already used a trial (under any account) gets no second one,
 * so a new email address on the same phone is not a new trial.
 */
class FreeTrial
{
    public function __construct(private ProvisioningService $provisioner) {}

    /** @param string[] $deviceIds the install's ids (X-Mvpn-Device / -Prev) */
    public function grantIfEligible(User $user, array $deviceIds = []): ?Subscription
    {
        $deviceIds = array_values(array_filter($deviceIds));
        $plan = Plan::trial();

        if (! $plan || $user->is_admin || $user->trial_started_at !== null) {
            return null;
        }
        // Customers who already paid (or were granted a plan) don't need a trial.
        if ($user->subscriptions()->exists() || $user->invoices()->where('status', 'paid')->exists()) {
            return null;
        }
        // The trial belongs to the phone: no device id, no trial. And a phone
        // that already had a trial (under any email/number) gets no second one.
        if ($deviceIds === [] || self::deviceUsedTrial($deviceIds)) {
            return null;
        }

        try {
            return DB::transaction(function () use ($user, $plan, $deviceIds) {
                // Lock the row: two quick sign-ins must not give two trials.
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($user->trial_started_at !== null) {
                    return null;
                }
                $user->forceFill([
                    'trial_started_at' => now(),
                    'trial_device' => $deviceIds[0] ?? null,
                ])->save();

                $invoice = Invoice::create([
                    'user_id' => $user->id,
                    'plan_code' => $plan->code,
                    'provider' => 'trial',
                    'payment_method' => 'free-trial',
                    'currency' => 'usd',
                    'amount_cents' => 0,
                    'status' => 'paid',
                    'idempotency_key' => 'trial:'.$user->id,
                    'paid_at' => now(),
                ]);

                $sub = $this->provisioner->fulfill($invoice);

                AuditLog::create([
                    'actor' => 'system:trial',
                    'action' => 'subscription.trial_started',
                    'target' => "subscription:{$sub->id}",
                    'meta' => array_filter(['plan' => $plan->code, 'days' => $plan->days, 'device' => $deviceIds[0] ?? null]),
                ]);

                return $sub;
            });
        } catch (\Throwable $e) {
            // Sign-in must never fail because of the trial.
            Log::error('Free trial for user '.$user->id.' failed: '.$e->getMessage());

            return null;
        }
    }

    /** Has this phone ever had a free trial, on any account? */
    public static function deviceUsedTrial(array $deviceIds): bool
    {
        if (User::whereIn('trial_device', $deviceIds)->exists()) {
            return true;
        }

        // Also a phone that was registered on someone's trial subscription.
        return Device::whereIn('fingerprint', $deviceIds)
            ->whereHas('subscription', fn ($q) => $q->whereIn('plan_code', Plan::where('is_trial', true)->pluck('code')))
            ->exists();
    }

    /** Should this customer still see the trial plan? Only while it is running. */
    public static function isRunning(?User $user): bool
    {
        return $user !== null && $user->subscriptions()
            ->whereIn('plan_code', Plan::where('is_trial', true)->pluck('code'))
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->exists();
    }
}
