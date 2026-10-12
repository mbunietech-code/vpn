<?php

namespace App\Services;

use App\Mail\PaymentProofMail;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Tells the admins a payment screenshot is waiting, so customers aren't kept waiting. */
class PaymentAlerts
{
    public function __construct(private Mailer $mailer) {}

    public function proofUploaded(Invoice $invoice): void
    {
        foreach ($this->recipients() as $to) {
            try {
                $this->mailer->send($to, new PaymentProofMail($invoice));
            } catch (Throwable $e) {
                // Never fail the customer's upload because an alert bounced.
                Log::warning('Payment alert email failed', ['to' => $to, 'invoice' => $invoice->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /** @return list<string> */
    public function recipients(): array
    {
        $configured = collect(explode(',', (string) config('services.mvpn.payment_alert_emails')))
            ->map(fn ($e) => strtolower(trim($e)))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));

        $list = $configured->isNotEmpty()
            ? $configured
            : User::where('is_admin', true)->whereNotNull('email')->pluck('email');

        return $list->unique()->values()->all();
    }
}
