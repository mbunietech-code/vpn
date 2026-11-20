<?php

namespace App\Mail;

use App\Models\Alert;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A new network/payment alert, emailed to the admins (see AlertAnalyzer). */
class AlertMail extends Mailable
{
    public function __construct(public Alert $alert) {}

    public function envelope(): Envelope
    {
        $level = match ($this->alert->severity) {
            'critical' => 'CRITICAL',
            'warn' => 'Warning',
            default => 'Info',
        };

        return new Envelope(subject: "Mbunie VPN {$level}: {$this->alert->title}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert', with: [
            'alert' => $this->alert,
            'url' => url('/admin/alerts'),
        ]);
    }
}
