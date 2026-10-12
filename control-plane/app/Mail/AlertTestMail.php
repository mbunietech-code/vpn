<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Does this inbox get our alerts?" — sent from Admin → Notifications. */
class AlertTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Mbunie VPN — test alert');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert-test');
    }
}
