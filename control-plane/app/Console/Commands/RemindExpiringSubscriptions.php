<?php

namespace App\Console\Commands;

use App\Mail\ExpiryReminderMail;
use App\Models\Subscription;
use App\Services\Mailer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Emails customers 3 days and 1 day before their plan ends. Each reminder is
 * sent once per end date; after a renewal the new end date gets its own.
 */
class RemindExpiringSubscriptions extends Command
{
    protected $signature = 'mvpn:remind-expiring';

    protected $description = 'Email customers whose VPN plan ends within 3 days / 1 day';

    public function handle(Mailer $mailer): int
    {
        $sent = 0;

        $due = Subscription::with('user')
            ->where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays(3)])
            ->get();

        foreach ($due as $sub) {
            $email = $sub->user?->email;
            if (! $email || $sub->user->status !== 'active') {
                continue;
            }

            $slot = $sub->expires_at->lte(now()->addDay()) ? 'reminded_1d_for' : 'reminded_3d_for';
            if ($sub->{$slot}?->equalTo($sub->expires_at)) {
                continue;
            }
            try {
                $mailer->send($email, new ExpiryReminderMail($sub));
            } catch (Throwable $e) {
                $this->warn("sub {$sub->id}: {$e->getMessage()}");

                continue;
            }

            $sub->forceFill([$slot => $sub->expires_at])->saveQuietly();
            $sent++;
        }

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
