<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\ProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * LOCAL-ONLY: simulate a completed payment so the full
 * pay -> auto-activate loop is demoable without live Stripe / Cryptomus keys.
 * Gated to the `local` environment in routes/api.php.
 */
class DevController extends Controller
{
    public function pay(Request $request, ProvisioningService $provisioner): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['nullable', 'string', 'exists:plans,code'],
            'currency' => ['nullable', 'in:usd,cny'],
        ]);

        // Reuse a pending invoice if one exists, else fabricate one.
        $invoice = Invoice::where('user_id', $request->user()->id)
            ->whereIn('status', ['pending', 'failed'])
            ->latest()
            ->first();

        if (! $invoice) {
            $plan = Plan::where('code', $data['plan'] ?? 'm1')->firstOrFail();
            $currency = $data['currency'] ?? 'usd';
            $invoice = Invoice::create([
                'user_id' => $request->user()->id,
                'plan_code' => $plan->code,
                'provider' => 'dev',
                'currency' => $currency,
                'amount_cents' => $plan->priceCents($currency),
                'status' => 'pending',
                'idempotency_key' => (string) Str::uuid(),
            ]);
        }

        $invoice->markPaid('dev_' . now()->timestamp);
        $sub = $provisioner->fulfill($invoice);

        return response()->json([
            'status' => 'activated',
            'subscription' => [
                'status' => $sub->status,
                'plan' => $sub->plan_code,
                'expires_at' => $sub->expires_at?->toIso8601String(),
                'sub_url' => url("/sub/{$sub->sub_token}"),
                'peers' => $sub->peers()->where('status', 'active')->count(),
            ],
        ]);
    }
}
