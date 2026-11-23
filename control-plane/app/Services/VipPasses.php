<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VipEvent;
use App\Models\VipPass;
use App\Payments\ConfirmsPayment;
use App\Payments\PaymentManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * VIP event passes, end to end:
 *   buy (QR page) → paid (webhook / status check / admin approval)
 *   → activate (subscription until the event ends, peers on every node)
 *   → claim (the app signs in with the code, one device at a time).
 *
 * Each pass has its own guest user (no email, no phone) so it never mixes
 * with a customer's own plan, and is switched off with the event.
 */
class VipPasses
{
    public function __construct(
        private ProvisioningService $provisioner,
        private PaymentManager $payments,
    ) {}

    // ---- buying ---------------------------------------------------------

    /**
     * A pending pass and its invoice. For hosted checkouts the caller gets
     * the provider's pay URL; for a manual method (Alipay QR) the buyer
     * uploads a screenshot on the pass page.
     *
     * @param  array{key:string,label:string,currency:string,amount_cents:int,method_id:?int}  $option
     * @return array{pass: VipPass, pay_url: ?string}
     */
    public function buy(VipEvent $event, array $option, int $devices, ?string $contact = null): array
    {
        $devices = max(1, min($devices, max(1, (int) $event->max_devices_per_order)));
        $manual = $option['method_id'] !== null;

        [$pass, $invoice] = DB::transaction(function () use ($event, $option, $devices, $contact, $manual) {
            $user = $this->guestUser($event);

            $pass = $event->passes()->create([
                'user_id' => $user->id,
                'devices' => $devices,
                'status' => 'pending',
                'source' => 'paid',
                'contact' => $contact ?: null,
            ]);

            $invoice = Invoice::create([
                'user_id' => $user->id,
                'plan_code' => VipEvent::PLAN_CODE,
                'provider' => $manual ? 'manual' : $option['key'],
                'payment_method' => $manual ? $option['label'] : null,
                'currency' => $option['currency'],
                'amount_cents' => $option['amount_cents'] * $devices,
                'status' => $manual ? 'pending_review' : 'pending',
                'idempotency_key' => (string) Str::uuid(),
                'expires_at' => $manual ? now()->addDays(2) : now()->addHours(2),
                'meta' => array_filter([
                    'vip_pass_id' => $pass->id,
                    'vip_event' => $event->name,
                    'devices' => $devices,
                    'contact' => $contact ?: null,
                    'description' => mb_substr("Mbunie VPN · {$event->name} · {$devices} device(s)", 0, 100),
                    'return_url' => $pass->pageUrl(),
                    'cancel_url' => $pass->pageUrl().'?cancelled=1',
                ]),
            ]);

            $pass->update(['invoice_id' => $invoice->id]);
            $user->update(['name' => "VIP · {$event->name} · #{$pass->id}"]);

            return [$pass, $invoice];
        });

        if ($manual) {
            return ['pass' => $pass->refresh(), 'pay_url' => null];
        }

        try {
            $checkout = $this->payments->driver($option['key'])->createCheckout($invoice);
        } catch (\Throwable $e) {
            $invoice->update(['status' => 'failed', 'meta' => array_merge($invoice->meta ?? [], ['error' => $e->getMessage()])]);
            report($e);
            throw new RuntimeException('Could not start the payment. Please try another method.', 0, $e);
        }

        $invoice->update(['provider_ref' => $checkout['provider_ref']]);

        return ['pass' => $pass->refresh(), 'pay_url' => $checkout['pay_url']];
    }

    /** Passes an admin hands out (guests, staff, cash payments): active at once. */
    public function give(VipEvent $event, int $devices, ?string $note, string $actor): VipPass
    {
        $pass = DB::transaction(function () use ($event, $devices, $note) {
            $user = $this->guestUser($event);
            $pass = $event->passes()->create([
                'user_id' => $user->id,
                'devices' => max(1, min($devices, 50)),
                'status' => 'pending',
                'source' => 'manual',
                'note' => $note ?: null,
            ]);
            $user->update(['name' => "VIP · {$event->name} · #{$pass->id}"]);

            return $pass;
        });

        $this->activate($pass);
        $this->audit($actor, 'vip.pass_given', $pass, ['devices' => $pass->devices, 'note' => $note]);

        return $pass->refresh();
    }

    private function guestUser(VipEvent $event): User
    {
        $user = new User;
        $user->forceFill([
            'name' => "VIP · {$event->name}",
            'locale' => 'en',
        ])->save();

        return $user;
    }

    /**
     * The buyer is waiting on the pass page: ask the provider directly
     * (at most every few seconds) instead of only waiting for its webhook.
     */
    public function refreshPayment(VipPass $pass): void
    {
        $invoice = $pass->invoice;
        if ($pass->status !== 'pending' || ! $invoice || $invoice->status !== 'pending' || ! $invoice->provider_ref) {
            return;
        }
        if (! Cache::add('vip:confirm:'.$invoice->id, 1, now()->addSeconds(5))) {
            return;
        }

        try {
            $gateway = $this->payments->driver($invoice->provider);
            if (! $gateway instanceof ConfirmsPayment || $gateway->key() === '') {
                return;
            }
            $event = $gateway->confirm($invoice);
            if ($event) {
                $event['invoice_id'] = $invoice->id; // only ever this pass's own invoice
                app(WebhookProcessor::class)->applyConfirmed($invoice->provider, $event);
            }
        } catch (\Throwable $e) {
            Log::warning("VIP pass {$pass->id}: payment check failed: ".$e->getMessage());
        }
    }

    // ---- activation -----------------------------------------------------

    /** Paid (or given): a subscription until the event ends. Safe to call twice. */
    public function activate(VipPass $pass): Subscription
    {
        return DB::transaction(function () use ($pass) {
            $pass = VipPass::whereKey($pass->id)->lockForUpdate()->firstOrFail();
            $event = $pass->event;

            $sub = $pass->subscription_id ? Subscription::find($pass->subscription_id) : null;
            if (! $sub) {
                $sub = Subscription::create([
                    'user_id' => $pass->user_id,
                    'plan_code' => VipEvent::PLAN_CODE,
                    'status' => 'pending',
                    'max_devices' => $pass->devices,
                    'started_at' => now(),
                    'expires_at' => $event->ends_at,
                ]);
            }

            $pass->update([
                'status' => 'active',
                'subscription_id' => $sub->id,
                'activated_at' => $pass->activated_at ?? now(),
            ]);
            if ($pass->invoice_id) {
                Invoice::whereKey($pass->invoice_id)->whereNull('provisioned_at')
                    ->update(['provisioned_at' => now(), 'subscription_id' => $sub->id]);
            }

            $this->applyState($pass->refresh(), $sub);

            return $sub->refresh();
        });
    }

    /** Called for every paid invoice: true when it belonged to a VIP pass. */
    public function activateForInvoice(Invoice $invoice): ?Subscription
    {
        $pass = VipPass::where('invoice_id', $invoice->id)->first();

        return $pass ? $this->activate($pass) : null;
    }

    // ---- admin switches -------------------------------------------------

    /** The event's end time or master switch changed: every pass follows. */
    public function syncEvent(VipEvent $event): void
    {
        // A plain loop: Collection::each() stops at the first callback that returns false.
        foreach ($event->passes()->where('status', 'active')->with('subscription', 'event')->get() as $pass) {
            if ($pass->subscription) {
                $this->applyState($pass, $pass->subscription);
            }
        }
    }

    public function setBlocked(VipPass $pass, bool $blocked, string $actor): void
    {
        $pass->update(['blocked' => $blocked]);
        if ($pass->subscription) {
            $this->applyState($pass->refresh(), $pass->subscription);
        }
        $this->audit($actor, $blocked ? 'vip.pass_blocked' : 'vip.pass_unblocked', $pass);
    }

    /** Forget the devices that used this code, so it can be used on new ones. */
    public function resetDevices(VipPass $pass, string $actor): int
    {
        if (! $pass->subscription_id) {
            return 0;
        }
        $devices = Device::where('subscription_id', $pass->subscription_id)->get();
        // Their sign-ins end too: the code must be entered again.
        $pass->user?->tokens()->delete();
        $devices->each->delete();
        $this->audit($actor, 'vip.devices_reset', $pass, ['devices' => $devices->count()]);

        return $devices->count();
    }

    /** Make the subscription match the pass: VPN on only while it should be. */
    private function applyState(VipPass $pass, Subscription $sub): void
    {
        $event = $pass->event;
        $live = $event->ends_at && $event->ends_at->isFuture();
        $on = $live && $event->access_enabled && ! $pass->blocked && $pass->status === 'active';

        $sub->forceFill([
            'expires_at' => $event->ends_at,
            'max_devices' => $pass->devices,
        ]);

        if ($on) {
            $sub->status = 'active';
            $sub->last_synced_at = now();
            $sub->save();
            $this->provisioner->syncPeers($sub, null);

            return;
        }

        $sub->save();
        if ($sub->status === 'active' || $sub->status === 'pending') {
            // Peers off within seconds.
            $this->provisioner->suspend($sub);
        }
        if (! $live && $sub->status !== 'expired') {
            $sub->update(['status' => 'expired']);
        }
    }

    // ---- the app signs in with the code ---------------------------------

    /**
     * @param  string[]  $deviceIds  the install's ids (X-Mvpn-Device, -Prev)
     * @return array{user: User, pass: VipPass}
     *
     * @throws VipClaimException
     */
    public function claim(string $code, array $deviceIds, ?string $platform = null, ?string $name = null): array
    {
        $deviceIds = array_values(array_unique(array_filter($deviceIds)));
        $pass = VipPass::with('event', 'subscription', 'invoice')->where('code', VipPass::normalizeCode($code))->first();

        if (! $pass) {
            throw new VipClaimException('invalid_code', 'This VIP code is not valid. Check it and try again.', 404);
        }
        if ($deviceIds === []) {
            throw new VipClaimException('device_required', 'Please update the app to use a VIP code.', 422);
        }

        $state = $pass->state();
        $message = match ($state) {
            'pending', 'review' => 'This pass is not paid yet. Finish the payment on the pass page first.',
            'ended' => 'This VIP pass has ended.',
            'blocked', 'off' => 'This VIP pass is switched off. Please contact the organiser.',
            'cancelled' => 'This VIP pass was cancelled.',
            default => null,
        };
        if ($message !== null) {
            throw new VipClaimException('pass_'.$state, $message, 403);
        }

        $sub = $pass->subscription;

        DB::transaction(function () use ($pass, $sub, $deviceIds, $platform, $name) {
            // Lock the pass so two phones can't take the last seat together.
            VipPass::whereKey($pass->id)->lockForUpdate()->first();

            $known = Device::where('subscription_id', $sub->id)->whereIn('fingerprint', $deviceIds)->first();
            if ($known) {
                if ($known->revoked_at !== null) {
                    throw new VipClaimException('device_revoked', 'This device was removed from the pass.', 403);
                }

                return;
            }

            $used = Device::where('subscription_id', $sub->id)->whereNull('revoked_at')->count();
            if ($used >= $pass->devices) {
                throw new VipClaimException('pass_full',
                    "This code is already used on {$used} device(s), the number paid for. Buy another pass for this device.", 409);
            }

            Device::create([
                'subscription_id' => $sub->id,
                'fingerprint' => $deviceIds[0],
                'platform' => in_array($platform, ['android', 'windows', 'macos', 'linux', 'ios'], true) ? $platform : null,
                'name' => $name,
                'last_seen_at' => now(),
            ]);
        });

        $this->audit('vip:'.$pass->code, 'vip.claimed', $pass, ['device' => $deviceIds[0]]);

        return ['user' => $pass->user, 'pass' => $pass->refresh()];
    }

    private function audit(string $actor, string $action, VipPass $pass, array $meta = []): void
    {
        AuditLog::create([
            'actor' => $actor,
            'action' => $action,
            'target' => "vip_pass:{$pass->id}",
            'meta' => $meta + ['event' => $pass->vip_event_id],
        ]);
    }
}
