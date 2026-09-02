<?php

namespace App\Payments\Drivers;

use App\Models\Invoice;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Cryptomus - hosted crypto checkout (USDT-TRC20 etc.). Best fallback where
 * card / Alipay fail, including inside China.
 *
 * Signature scheme: md5(base64(json_body) . PAYMENT_KEY).
 */
class CryptomusGateway implements PaymentGateway
{
    private string $merchantId;
    private string $paymentKey;

    public function __construct()
    {
        $this->merchantId = (string) config('services.cryptomus.merchant_id');
        $this->paymentKey = (string) config('services.cryptomus.payment_key');
    }

    public function key(): string
    {
        return $this->paymentKey;
    }

    public function createCheckout(Invoice $invoice): array
    {
        // Cryptomus prices in fiat and settles in the customer's chosen coin.
        $body = [
            'amount' => number_format($invoice->amount_cents / 100, 2, '.', ''),
            'currency' => strtoupper($invoice->currency),
            'order_id' => (string) $invoice->id,
            'url_return' => config('app.url') . '/pay/return?invoice=' . $invoice->id,
            'url_callback' => config('app.url') . '/webhooks/cryptomus',
            'lifetime' => 1800,
        ];

        $resp = Http::withHeaders([
            'merchant' => $this->merchantId,
            'sign' => md5(base64_encode(json_encode($body)) . $this->paymentKey),
        ])->post('https://api.cryptomus.com/v1/payment', $body)
            ->throw()
            ->json();

        return [
            'pay_url' => $resp['result']['url'],
            'provider_ref' => $resp['result']['uuid'],
        ];
    }

    public function verifySignature(Request $request): bool
    {
        $data = $request->json()->all();
        $sign = $data['sign'] ?? null;
        unset($data['sign']);
        if (! $sign || ! $this->paymentKey) {
            return false;
        }

        $expected = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . $this->paymentKey);

        return hash_equals($expected, $sign);
    }

    public function parseEvent(Request $request): array
    {
        $d = $request->json()->all();
        $status = $d['status'] ?? '';

        // "paid" / "paid_over" = success; "wrong_amount"/"cancel"/"fail" = failed
        $type = match (true) {
            in_array($status, ['paid', 'paid_over'], true) => 'paid',
            in_array($status, ['fail', 'cancel', 'wrong_amount', 'system_fail'], true) => 'failed',
            $status === 'refund' => 'refunded',
            default => 'ignored',
        };

        return [
            'event_id' => $d['uuid'] ?? null,
            'type' => $type,
            'provider_ref' => $d['uuid'] ?? null,
            'amount_cents' => isset($d['amount']) ? (int) round(((float) $d['amount']) * 100) : null,
            'currency' => isset($d['currency']) ? strtolower($d['currency']) : null,
            'invoice_id' => isset($d['order_id']) ? (int) $d['order_id'] : null,
        ];
    }
}
