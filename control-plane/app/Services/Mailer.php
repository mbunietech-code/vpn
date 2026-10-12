<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Sends a Mailable through Brevo's HTTP API when a key is set (shared hosting
 * often blocks SMTP), otherwise through Laravel's mailer.
 */
class Mailer
{
    public function send(string $to, Mailable $mail): void
    {
        $brevoKey = config('services.brevo.key');

        if (! $brevoKey) {
            Mail::to($to)->send($mail);

            return;
        }

        $resp = Http::withHeaders(['api-key' => $brevoKey, 'accept' => 'application/json'])
            ->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => [
                    'email' => config('mail.from.address'),
                    'name' => config('mail.from.name', 'Mbunie VPN'),
                ],
                'to' => [['email' => $to]],
                'subject' => $mail->envelope()->subject,
                'htmlContent' => $mail->render(),
            ]);

        if ($resp->failed()) {
            throw new RuntimeException('Brevo send failed: '.$resp->body());
        }
    }
}
