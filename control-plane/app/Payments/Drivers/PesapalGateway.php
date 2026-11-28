<?php

namespace App\Payments\Drivers;

use App\Models\Invoice;
use App\Payments\ConfirmsPayment;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Pesapal API 3.0 — hosted checkout for Visa / Mastercard (and East African
 * mobile money). Docs: https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/api-reference
 *
 *  - token:   POST /api/Auth/RequestToken            {consumer_key, consumer_secret}
 *  - IPN:     POST /api/URLSetup/RegisterIPN          {url, ipn_notification_type} → ipn_id
 *  - order:   POST /api/Transactions/SubmitOrderRequest → {order_tracking_id, redirect_url}
 *  - status:  GET  /api/Transactions/GetTransactionStatus?orderTrackingId=…
 *
 * Pesapal IPNs are not signed: they only say "order X changed". We always ask
 * Pesapal's API for the real status before activating anything.
 */
class PesapalGateway implements ConfirmsPayment, PaymentGateway
{
    private string $consumerKey;

    private string $consumerSecret;

    public function __construct()
    {
        $this->consumerKey = (string) config('services.pesapal.consumer_key');
        $this->consumerSecret = (string) config('services.pesapal.consumer_secret');
    }

    public function key(): string
    {
        return $this->consumerKey !== '' && $this->consumerSecret !== '' ? $this->consumerKey : '';
    }

    private function base(): string
    {
        return filter_var(config('services.pesapal.sandbox'), FILTER_VALIDATE_BOOL)
            ? 'https://cybqa.pesapal.com/pesapalv3'
            : 'https://pay.pesapal.com/v3';
    }

    private function token(): string
    {
        // Tokens live 5 minutes.
        return Cache::remember('pesapal.token.'.md5($this->base().$this->consumerKey), now()->addMinutes(4), function () {
            $resp = Http::acceptJson()->asJson()->timeout(20)
                ->post($this->base().'/api/Auth/RequestToken', [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                ]);

            $token = $resp->json('token');
            if (! $token) {
                throw new RuntimeException('Pesapal token failed: '.$resp->body());
            }

            return $token;
        });
    }

    private function api()
    {
        return Http::acceptJson()->asJson()->timeout(25)->withToken($this->token());
    }

    /** Our IPN (webhook) id at Pesapal: from config, or registered once and remembered. */
    private function ipnId(): string
    {
        $configured = (string) config('services.pesapal.ipn_id');
        if ($configured !== '') {
            return $configured;
        }

        $url = route('webhooks.pesapal');

        return Cache::rememberForever('pesapal.ipn.'.md5($this->base().$this->consumerKey.$url), function () use ($url) {
            $resp = $this->api()->post($this->base().'/api/URLSetup/RegisterIPN', [
                'url' => $url,
                'ipn_notification_type' => 'GET',
            ]);
            $id = $resp->json('ipn_id');
            if (! $id) {
                throw new RuntimeException('Pesapal IPN registration failed: '.$resp->body());
            }

            return (string) $id;
        });
    }

    public function createCheckout(Invoice $invoice): array
    {
        $meta = $invoice->meta ?? [];
        $contact = (string) ($meta['contact'] ?? $invoice->user?->email ?? $invoice->user?->phone ?? '');
        $billing = ['first_name' => 'Mbunie', 'last_name' => 'VPN', 'country_code' => ''];
        if (str_contains($contact, '@')) {
            $billing['email_address'] = $contact;
        } elseif ($contact !== '') {
            $billing['phone_number'] = $contact;
        } else {
            // Pesapal needs an email or a phone for the receipt.
            $billing['email_address'] = (string) config('services.pesapal.receipt_email', 'support@mbuniehub.com');
        }

        $resp = $this->api()->post($this->base().'/api/Transactions/SubmitOrderRequest', [
            'id' => 'MVPN'.$invoice->id,
            'currency' => strtoupper($invoice->currency),
            'amount' => round($invoice->amount_cents / 100, 2),
            'description' => mb_substr((string) ($meta['description'] ?? 'Mbunie VPN'), 0, 100),
            'callback_url' => $meta['return_url'] ?? url('/pay/return?invoice='.$invoice->id.'&status=ok'),
            'cancellation_url' => $meta['cancel_url'] ?? url('/pay/return?invoice='.$invoice->id.'&status=cancel'),
            'notification_id' => $this->ipnId(),
            'billing_address' => $billing,
        ]);

        $tracking = $resp->json('order_tracking_id');
        $redirect = $resp->json('redirect_url');
        if (! $tracking || ! $redirect) {
            throw new RuntimeException('Pesapal order failed: '.$resp->body());
        }

        return ['pay_url' => $redirect, 'provider_ref' => (string) $tracking];
    }

    public function verifySignature(Request $request): bool
    {
        // Unsigned: the real check is the status query in parseEvent().
        return filled($this->trackingId($request));
    }

    public function parseEvent(Request $request): array
    {
        $tracking = (string) $this->trackingId($request);
        $reference = $request->input('OrderMerchantReference') ?? $request->input('orderMerchantReference');

        return $this->status($tracking, $reference ? (string) $reference : null);
    }

    public function confirm(Invoice $invoice): ?array
    {
        if (! $invoice->provider_ref) {
            return null;
        }

        return $this->status($invoice->provider_ref, 'MVPN'.$invoice->id);
    }

    /** Ask Pesapal what happened to an order. */
    private function status(string $tracking, ?string $reference): array
    {
        $type = 'ignored';
        $amountCents = null;
        $currency = null;
        $code = 'x';

        try {
            $data = $this->api()
                ->get($this->base().'/api/Transactions/GetTransactionStatus', ['orderTrackingId' => $tracking])
                ->json() ?? [];

            // 0 invalid, 1 completed, 2 failed, 3 reversed
            $code = (string) ($data['status_code'] ?? 'x');
            $type = match ($code) {
                '1' => 'paid',
                '2' => 'failed',
                '3' => 'refunded',
                default => 'ignored',
            };
            if (isset($data['amount'])) {
                $amountCents = (int) round(((float) $data['amount']) * 100);
            }
            if (! empty($data['currency'])) {
                $currency = strtolower((string) $data['currency']);
            }
            $reference = $data['merchant_reference'] ?? $reference;
        } catch (\Throwable $e) {
            report($e);
        }

        $invoiceId = $reference && preg_match('/^MVPN(\d+)$/', (string) $reference, $m) ? (int) $m[1] : null;
        $invoiceId ??= Invoice::where('provider', 'pesapal')->where('provider_ref', $tracking)->value('id');

        return [
            // One record per order *and* status: "pending" first, "paid" later.
            'event_id' => $tracking.':'.$code,
            'type' => $type,
            'provider_ref' => $tracking,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'invoice_id' => $invoiceId,
        ];
    }

    private function trackingId(Request $r): ?string
    {
        return $r->input('OrderTrackingId') ?? $r->input('orderTrackingId');
    }
}
