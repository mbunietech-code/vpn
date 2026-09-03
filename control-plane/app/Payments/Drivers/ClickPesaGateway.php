<?php

namespace App\Payments\Drivers;

use App\Models\Invoice;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ClickPesa — Tanzanian aggregator: M-Pesa, Tigo Pesa, Airtel Money, HaloPesa,
 * bank transfer and card, via a hosted checkout page. Charged in TZS.
 *
 * Docs: https://docs.clickpesa.com
 *  - token:    POST /third-parties/generate-token   (headers: client-id, api-key)
 *  - checkout: POST /third-parties/generate-checkout-url  (Bearer token)
 *  - confirm:  GET  /third-parties/payments/{orderReference}
 *
 * Webhooks can be spoofed, so we re-query the payment status on ClickPesa's
 * API before activating anything (checksum verification is fiddly and their
 * dashboard config varies).
 */
class ClickPesaGateway implements PaymentGateway
{
    private const BASE = 'https://api.clickpesa.com';

    private string $clientId;
    private string $apiKey;
    private string $checksumSecret;

    public function __construct()
    {
        $this->clientId = (string) config('services.clickpesa.client_id');
        $this->apiKey = (string) config('services.clickpesa.api_key');
        $this->checksumSecret = (string) config('services.clickpesa.checksum_secret');
    }

    public function key(): string
    {
        return $this->clientId;
    }

    private function token(): string
    {
        return Cache::remember('clickpesa.token', now()->addMinutes(50), function () {
            $resp = Http::withHeaders([
                'client-id' => $this->clientId,
                'api-key' => $this->apiKey,
            ])->post(self::BASE . '/third-parties/generate-token');

            $token = $resp->json('token');
            if (! $token) {
                throw new RuntimeException('ClickPesa token failed: ' . $resp->body());
            }

            return $token; // already "Bearer <jwt>"
        });
    }

    public function createCheckout(Invoice $invoice): array
    {
        // ClickPesa amounts are whole TZS (not cents).
        $amount = (int) round($invoice->amount_cents / 100);
        $ref = 'MVPN' . $invoice->id;

        $body = [
            'totalPrice' => $amount,
            'orderReference' => $ref,
            'orderCurrency' => strtoupper($invoice->currency), // TZS
        ];
        if ($this->checksumSecret) {
            $body['checksum'] = $this->checksum($body);
        }

        $resp = Http::withHeaders(['Authorization' => $this->token()])
            ->post(self::BASE . '/third-parties/generate-checkout-url', $body);

        $link = $resp->json('checkoutLink') ?? $resp->json('checkout_url');
        if (! $link) {
            throw new RuntimeException('ClickPesa checkout failed: ' . $resp->body());
        }

        return ['pay_url' => $link, 'provider_ref' => $ref];
    }

    public function verifySignature(Request $request): bool
    {
        // The real check happens in parseEvent (server-to-server confirm).
        return (bool) $this->orderRef($request);
    }

    public function parseEvent(Request $request): array
    {
        $ref = $this->orderRef($request);
        $invoiceId = $ref ? (int) preg_replace('/\D/', '', $ref) : null;

        $status = 'ignored';
        $amountCents = null;
        $currency = null;

        if ($ref) {
            try {
                $resp = Http::withHeaders(['Authorization' => $this->token()])
                    ->get(self::BASE . '/third-parties/payments/' . $ref);
                $data = $resp->json();
                $s = strtoupper((string) ($data['status'] ?? $data['data']['status'] ?? ''));
                $status = match (true) {
                    in_array($s, ['SUCCESS', 'PAID', 'COMPLETED', 'SETTLED'], true) => 'paid',
                    in_array($s, ['FAILED', 'CANCELLED', 'REJECTED'], true) => 'failed',
                    default => 'ignored',
                };
                $amt = $data['collectedAmount'] ?? $data['amount'] ?? $data['data']['collectedAmount'] ?? null;
                if ($amt !== null) {
                    $amountCents = (int) round(((float) $amt) * 100);
                }
                $currency = strtolower((string) ($data['currency'] ?? $data['collectedCurrency'] ?? 'tzs'));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [
            'event_id' => $request->input('id') ?? $request->input('data.id') ?? $ref,
            'type' => $status,
            'provider_ref' => $ref,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'invoice_id' => $invoiceId,
        ];
    }

    private function orderRef(Request $r): ?string
    {
        return $r->input('orderReference')
            ?? $r->input('data.orderReference')
            ?? $r->input('order_reference');
    }

    private function checksum(array $params): string
    {
        ksort($params);
        return hash_hmac('sha256', implode('', array_map('strval', $params)), $this->checksumSecret);
    }
}
