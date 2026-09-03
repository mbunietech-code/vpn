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
 *  - checkout: POST /webshop/generate-checkout-url   (Bearer token; needs merchantId,
 *              returns the hosted-checkout URL as a bare string)
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
    private string $merchantId;
    private string $checksumSecret;

    public function __construct()
    {
        $this->clientId = (string) config('services.clickpesa.client_id');
        $this->apiKey = (string) config('services.clickpesa.api_key');
        $this->merchantId = (string) config('services.clickpesa.merchant_id');
        $this->checksumSecret = (string) config('services.clickpesa.checksum_secret');
    }

    public function key(): string
    {
        return $this->clientId !== '' && $this->apiKey !== '' ? $this->clientId : '';
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

    /** Merchant ID from config, or decoded from the JWT `id` claim as a fallback. */
    private function merchantId(): string
    {
        if ($this->merchantId !== '') {
            return $this->merchantId;
        }

        $jwt = trim(str_ireplace('Bearer ', '', $this->token()));
        $parts = explode('.', $jwt);
        if (count($parts) === 3) {
            $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
            if (is_array($payload) && ! empty($payload['id'])) {
                return (string) $payload['id'];
            }
        }

        throw new RuntimeException('ClickPesa merchant ID not configured and not derivable from token.');
    }

    public function createCheckout(Invoice $invoice): array
    {
        // ClickPesa amounts are whole currency units (not cents), passed as strings.
        $amount = (string) (int) round($invoice->amount_cents / 100);
        $ref = 'MVPN' . $invoice->id;

        $body = [
            'totalPrice' => $amount,
            'orderReference' => $ref,
            'orderCurrency' => strtoupper($invoice->currency), // TZS
            'merchantId' => $this->merchantId(),
        ];

        $resp = Http::withHeaders(['Authorization' => $this->token()])
            ->asJson()
            ->post(self::BASE . '/webshop/generate-checkout-url', $body);

        $link = $this->extractUrl($resp->json(), $resp->body());
        if (! $link || ! str_starts_with($link, 'http')) {
            throw new RuntimeException('ClickPesa checkout failed: ' . $resp->body());
        }

        return ['pay_url' => $link, 'provider_ref' => $ref];
    }

    /**
     * The webshop endpoint serialises the URL string as a char-indexed object
     * ({"0":"h","1":"t",...}) alongside a "depricatedMessage" key. Reassemble it.
     */
    private function extractUrl(mixed $json, string $raw): ?string
    {
        if (is_string($json) && $json !== '') {
            return $json;
        }

        if (is_array($json)) {
            foreach (['checkoutLink', 'checkout_url', 'url'] as $k) {
                if (! empty($json[$k]) && is_string($json[$k])) {
                    return $json[$k];
                }
            }

            $chars = [];
            foreach ($json as $k => $v) {
                if (is_numeric($k) && is_string($v)) {
                    $chars[(int) $k] = $v;
                }
            }
            if ($chars) {
                ksort($chars);
                return implode('', $chars);
            }
        }

        $trimmed = trim($raw, "\" \n\r\t");

        return str_starts_with($trimmed, 'http') ? $trimmed : null;
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
}
