<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the customer: their VPN plan ends soon. */
class ExpiryReminderMail extends Mailable
{
    public function __construct(public Subscription $subscription) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->hoursLeft() <= 30
            ? 'Your Mbunie VPN ends tomorrow'
            : 'Your Mbunie VPN ends in '.$this->daysLeft().' days');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.expiry-reminder', with: [
            'ends' => $this->subscription->expires_at->timezone('Africa/Dar_es_Salaam')->format('d M Y, H:i').' (EAT)',
            'days' => $this->daysLeft(),
            'renewUrl' => rtrim((string) config('services.eduhub.url', 'https://mbuniehub.com'), '/'),
        ]);
    }

    private function hoursLeft(): int
    {
        return max(0, (int) now()->diffInHours($this->subscription->expires_at));
    }

    private function daysLeft(): int
    {
        return max(1, (int) ceil($this->hoursLeft() / 24));
    }
}
