<?php

namespace App\Payments\Drivers;

use App\Models\Invoice;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout - covers card, Alipay and WeChat Pay as payment methods.
 * Uses the REST API directly (no SDK) via a Bearer secret key.
 *
 * Alipay / WeChat Pay require the invoice currency to be one Stripe supports
 * for them (cny works for both; usd works for card).
 */
class StripeGateway implements PaymentGateway
{
    private string $secret;
    private string $webhookSecret;

    public function __construct()
    {
        $this->secret = (string) config('services.stripe.secret');
        $this->webhookSecret = (string) config('services.stripe.webhook_secret');
    }

    public function key(): string
    {
        return $this->secret;
    }

    public function createCheckout(Invoice $invoice): array
    {
        $methods = $invoice->currency === 'cny'
            ? ['alipay', 'wechat_pay', 'card']
            : ['card', 'alipay'];

        $payload = [
            'mode' => 'payment',
            'success_url' => config('app.url') . '/pay/return?invoice=' . $invoice->id . '&status=ok',
            'cancel_url' => config('app.url') . '/pay/return?invoice=' . $invoice->id . '&status=cancel',
            'client_reference_id' => (string) $invoice->id,
            'payment_method_types' => $methods,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $invoice->currency,
                    'unit_amount' => $invoice->amount_cents,
                    'product_data' => ['name' => 'Mbunie VPN - ' . $invoice->plan_code],
                ],
            ]],
            'metadata' => ['invoice_id' => (string) $invoice->id],
        ];

        if (in_array('wechat_pay', $methods, true)) {
            $payload['payment_method_options'] = ['wechat_pay' => ['client' => 'web']];
        }

        $resp = Http::withToken($this->secret)
            ->asForm()
            ->post('https://api.stripe.com/v1/checkout/sessions', $this->flatten($payload))
            ->throw()
            ->json();

        return ['pay_url' => $resp['url'], 'provider_ref' => $resp['id']];
    }

    public function verifySignature(Request $request): bool
    {
        $header = $request->header('Stripe-Signature', '');
        $parts = collect(explode(',', $header))
            ->mapWithKeys(function ($p) {
                [$k, $v] = array_pad(explode('=', $p, 2), 2, null);
                return [$k => $v];
            });

        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? null;
        if (! $timestamp || ! $signature || ! $this->webhookSecret) {
            return false;
        }

        // 5-minute replay tolerance
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $this->webhookSecret);

        return hash_equals($expected, $signature);
    }

    public function parseEvent(Request $request): array
    {
        $event = $request->json()->all();
        $type = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];

        $map = [
            'checkout.session.completed' => 'paid',
            'checkout.session.async_payment_succeeded' => 'paid',
            'checkout.session.async_payment_failed' => 'failed',
            'charge.refunded' => 'refunded',
        ];

        return [
            'event_id' => $event['id'] ?? null,
            'type' => $map[$type] ?? 'ignored',
            'provider_ref' => $object['id'] ?? null,
            'amount_cents' => isset($object['amount_total']) ? (int) $object['amount_total'] : null,
            'currency' => isset($object['currency']) ? strtolower($object['currency']) : null,
            'invoice_id' => isset($object['metadata']['invoice_id'])
                ? (int) $object['metadata']['invoice_id']
                : (isset($object['client_reference_id']) ? (int) $object['client_reference_id'] : null),
        ];
    }

    /** Stripe's form API wants bracketed keys: line_items[0][price_data][currency]. */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? $key : "{$prefix}[{$key}]";
            if (is_array($value)) {
                $out += $this->flatten($value, $name);
            } else {
                $out[$name] = $value;
            }
        }
        return $out;
    }
}
