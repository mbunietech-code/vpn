<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the admins: a customer uploaded a payment screenshot to review. */
class PaymentProofMail extends Mailable
{
    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Mbunie VPN — payment to review: '.$this->amount().' from '.$this->customer());
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-proof', with: [
            'amount' => $this->amount(),
            'customer' => $this->customer(),
            'reviewUrl' => rtrim((string) config('services.eduhub.url', 'https://mbuniehub.com'), '/').'/admin/vpn/payments',
        ]);
    }

    public function amount(): string
    {
        $cur = $this->invoice->currency;

        return strtoupper($cur).' '.number_format($this->invoice->amount_cents / 100, $cur === 'tzs' ? 0 : 2);
    }

    public function customer(): string
    {
        return $this->invoice->user?->email ?? $this->invoice->user?->phone ?? 'a customer';
    }
}
