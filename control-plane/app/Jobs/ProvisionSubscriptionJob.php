<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\AuditLog;
use App\Services\ProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched the moment a payment webhook is verified. Activates the
 * subscription and provisions peers - target < 60s (FR-NEW-03).
 */
class ProvisionSubscriptionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public array $backoff = [5, 15, 30, 60];

    public function __construct(public int $invoiceId) {}

    public function handle(ProvisioningService $provisioner): void
    {
        $invoice = Invoice::with('user')->findOrFail($this->invoiceId);

        if ($invoice->status !== 'paid') {
            Log::warning("ProvisionSubscriptionJob: invoice {$invoice->id} not paid ({$invoice->status})");
            return;
        }

        $sub = $provisioner->fulfill($invoice);

        AuditLog::create([
            'actor' => 'system',
            'action' => 'subscription.provisioned',
            'target' => "subscription:{$sub->id}",
            'meta' => [
                'invoice_id' => $invoice->id,
                'plan' => $invoice->plan_code,
                'expires_at' => $sub->expires_at?->toIso8601String(),
                'peers' => $sub->peers()->where('status', 'active')->count(),
            ],
        ]);
    }
}
