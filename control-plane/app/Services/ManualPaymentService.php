<?php

namespace App\Services;

use App\Jobs\ProvisionSubscriptionJob;
use App\Models\AuditLog;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;

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

    /** Delete a payment record (and its screenshot). The subscription is not touched. */
    public function delete(Invoice $invoice, string $actor): void
    {
        AuditLog::create([
            'actor' => $actor,
            'action' => 'invoice.deleted',
            'target' => "invoice:{$invoice->id}",
            'meta' => [
                'user_id' => $invoice->user_id,
                'plan' => $invoice->plan_code,
                'amount' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'status' => $invoice->status,
                'provider' => $invoice->provider,
            ],
        ]);
        if ($invoice->proof_path) {
            Storage::delete($invoice->proof_path);
        }
        $invoice->delete();
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
