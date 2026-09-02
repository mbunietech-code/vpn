<?php

namespace App\Services;

use App\Jobs\ProvisionSubscriptionJob;
use App\Models\Alert;
use App\Models\Invoice;
use App\Models\WebhookEvent;
use App\Payments\PaymentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookProcessor
{
    public function __construct(private PaymentManager $payments) {}

    /** @return array{status:int, body:array} */
    public function handle(string $provider, Request $request): array
    {
        $gateway = $this->payments->driver($provider);
        $signatureOk = $gateway->verifySignature($request);

        $event = $signatureOk ? $gateway->parseEvent($request) : [];

        $record = WebhookEvent::updateOrCreate(
            ['provider' => $provider, 'event_id' => $event['event_id'] ?? sha1($request->getContent())],
            [
                'type' => $event['type'] ?? null,
                'signature_status' => $signatureOk ? 'ok' : 'bad',
                'payload' => $request->json()->all() ?: ['raw' => $request->getContent()],
            ]
        );

        if (! $signatureOk) {
            Alert::create([
                'severity' => 'warn',
                'source' => 'webhook',
                'title' => "Bad webhook signature ({$provider})",
                'body' => 'A webhook was rejected for an invalid signature. Possible misconfiguration or probing.',
                'context' => ['ip' => $request->ip()],
            ]);
            return ['status' => 400, 'body' => ['error' => 'bad signature']];
        }

        if ($record->processed) {
            return ['status' => 200, 'body' => ['status' => 'duplicate ignored']];
        }

        $result = $this->apply($event);

        $record->update(['processed' => true, 'result' => $result]);

        return ['status' => 200, 'body' => ['status' => $result]];
    }

    private function apply(array $event): string
    {
        $invoice = $event['invoice_id'] ? Invoice::find($event['invoice_id']) : null;
        if (! $invoice) {
            return 'invoice not found';
        }

        return match ($event['type']) {
            'paid' => $this->markPaidAndProvision($invoice, $event),
            'failed' => $this->markFailed($invoice),
            'refunded' => $this->refund($invoice),
            default => 'ignored',
        };
    }

    private function markFailed(Invoice $invoice): string
    {
        if ($invoice->status === 'pending') {
            $invoice->update(['status' => 'failed']);
        }
        return 'payment failed';
    }

    private function markPaidAndProvision(Invoice $invoice, array $event): string
    {
        if ($invoice->status === 'paid') {
            return 'already paid';
        }

        // Amount + currency assertion (FR-NEW-09). Allow small provider fee slack.
        $expected = $invoice->amount_cents;
        $got = $event['amount_cents'] ?? $expected;
        $currencyOk = ! $event['currency'] || $event['currency'] === $invoice->currency;

        if (! $currencyOk || $got < $expected - 2) {
            Alert::create([
                'severity' => 'critical',
                'source' => 'webhook',
                'title' => "Payment amount/currency mismatch on invoice {$invoice->id}",
                'body' => "expected {$expected} {$invoice->currency}, got {$got} " . ($event['currency'] ?? '?'),
                'context' => $event,
            ]);
            return 'amount mismatch - not activated';
        }

        $invoice->markPaid($event['provider_ref'] ?? null);
        ProvisionSubscriptionJob::dispatch($invoice->id);

        return 'paid + provisioning dispatched';
    }

    private function refund(Invoice $invoice): string
    {
        $invoice->update(['status' => 'refunded']);
        $sub = $invoice->user->subscriptions()
            ->where('plan_code', $invoice->plan_code)
            ->latest()->first();
        if ($sub) {
            app(ProvisioningService::class)->suspend($sub, 'refund');
        }
        return 'refunded + suspended';
    }
}
