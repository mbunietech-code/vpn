<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Payments\PaymentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function __construct(private PaymentManager $payments) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,code'],
            'provider' => ['required', 'string', 'in:stripe,cryptomus,clickpesa'],
            'currency' => ['required', 'string', 'in:usd,cny,tzs'],
        ]);

        // Some providers only settle in one currency.
        $forced = PaymentManager::CURRENCY[$data['provider']] ?? null;
        $currency = $forced ?? $data['currency'];

        $plan = Plan::where('code', $data['plan'])->where('is_active', true)->firstOrFail();
        $user = $request->user();

        $amount = $plan->priceCents($currency);
        if ($amount <= 0) {
            return response()->json([
                'error' => 'currency_unavailable',
                'message' => "This plan has no {$currency} price.",
            ], 422);
        }

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'plan_code' => $plan->code,
            'provider' => $data['provider'],
            'currency' => $currency,
            'amount_cents' => $amount,
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addMinutes(30),
        ]);

        try {
            $checkout = $this->payments->driver($data['provider'])->createCheckout($invoice);
        } catch (\Throwable $e) {
            $invoice->update(['status' => 'failed', 'meta' => ['error' => $e->getMessage()]]);
            report($e);

            return response()->json(['error' => 'checkout_failed', 'message' => 'Could not start payment.'], 502);
        }

        $invoice->update(['provider_ref' => $checkout['provider_ref']]);

        return response()->json([
            'invoice_id' => $invoice->id,
            'pay_url' => $checkout['pay_url'],
            'amount' => $invoice->amount_cents,
            'currency' => $invoice->currency,
            'expires_at' => $invoice->expires_at->toIso8601String(),
        ]);
    }
}
