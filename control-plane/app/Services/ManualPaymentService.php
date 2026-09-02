<?php

namespace App\Services;

use App\Jobs\ProvisionSubscriptionJob;
use App\Models\AuditLog;
use App\Models\Invoice;

class ManualPaymentService
{
    public function approve(Invoice $invoice, string $actor, ?string $note = null): void
    {
        if ($invoice->status === 'paid') {
            return;
        }

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
            'reviewed_at' => now(),
            'reviewed_by' => $actor,
            'review_note' => $note,
        ]);

        ProvisionSubscriptionJob::dispatchSync($invoice->id);

        AuditLog::create([
            'actor' => $actor,
            'action' => 'invoice.approved',
            'target' => "invoice:{$invoice->id}",
            'meta' => ['plan' => $invoice->plan_code, 'amount' => $invoice->amount_cents],
        ]);
    }

    public function reject(Invoice $invoice, string $actor, string $reason): void
    {
        $invoice->update([
            'status' => 'failed',
            'reviewed_at' => now(),
            'reviewed_by' => $actor,
            'review_note' => $reason,
        ]);

        AuditLog::create([
            'actor' => $actor,
            'action' => 'invoice.rejected',
            'target' => "invoice:{$invoice->id}",
            'meta' => ['reason' => $reason],
        ]);
    }
}
