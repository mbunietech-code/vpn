<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Payments\PaymentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * v1 manual payment flow:
 *   1. GET  /api/payment-methods          → QR codes + instructions
 *   2. POST /api/checkout {provider:manual}→ creates a pending_review invoice
 *   3. POST /api/invoices/{id}/proof       → user uploads payment screenshot
 *   4. admin approves in the panel         → ProvisionSubscriptionJob runs
 */
class PaymentController extends Controller
{
    public function methods(Request $request, PaymentManager $payments): JsonResponse
    {
        $methods = PaymentMethod::where('is_active', true)
            ->orderBy('sort')
            ->get()
            ->map(fn (PaymentMethod $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'label' => $m->label,
                'currency' => $m->currency,
                'qr_url' => $m->qrUrl(),
                'account_ref' => $m->account_ref,
                'instructions' => $m->instructions,
            ]);

        // Instant (hosted-checkout) providers that have credentials set.
        $labels = [
            'clickpesa' => 'M-Pesa · Tigo · Airtel · Bank (TZS)',
            'stripe' => 'Card · Alipay · WeChat',
            'cryptomus' => 'Crypto (USDT)',
        ];
        $instant = collect($payments->available())->map(fn ($p) => [
            'provider' => $p,
            'label' => $labels[$p] ?? $p,
            'currency' => PaymentManager::CURRENCY[$p], // null = user picks usd/cny
        ])->values();

        return response()->json([
            'methods' => $methods,
            'instant' => $instant,
        ]);
    }

    public function createManualInvoice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,code'],
            'currency' => ['required', 'string', 'in:usd,cny'],
            'method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
        ]);

        $plan = Plan::where('code', $data['plan'])->where('is_active', true)->firstOrFail();
        $method = isset($data['method_id']) ? PaymentMethod::find($data['method_id']) : null;

        $invoice = Invoice::create([
            'user_id' => $request->user()->id,
            'plan_code' => $plan->code,
            'provider' => 'manual',
            'payment_method' => $method?->label,
            'currency' => $data['currency'],
            'amount_cents' => $plan->priceCents($data['currency']),
            'status' => 'pending_review',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addDays(2),
        ]);

        return response()->json([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->amount_cents,
            'currency' => $invoice->currency,
            'upload_url' => url("/api/invoices/{$invoice->id}/proof"),
        ]);
    }

    public function uploadProof(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($invoice->user_id === $request->user()->id, 403);
        abort_unless(in_array($invoice->status, ['pending', 'pending_review'], true), 409,
            'Invoice already processed.');

        $request->validate([
            'proof' => ['required', 'image', 'max:8192'], // 8 MB
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $path = $request->file('proof')->store("proofs/{$invoice->id}");

        $invoice->update([
            'proof_path' => $path,
            'status' => 'pending_review',
            'meta' => array_merge($invoice->meta ?? [], [
                'user_note' => $request->string('note')->toString(),
                'proof_uploaded_at' => now()->toIso8601String(),
            ]),
        ]);

        return response()->json(['status' => 'submitted']);
    }
}
