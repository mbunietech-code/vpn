<?php

namespace App\Mail;

use App\Models\Alert;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A node is filling up (data quota or subscribers): time to add a server. */
class NodeCapacityMail extends Mailable
{
    public function __construct(public Alert $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Mbunie VPN — '.$this->alert->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.node-capacity', with: [
            'alert' => $this->alert,
            'url' => url('/admin/nodes'),
        ]);
    }
}
