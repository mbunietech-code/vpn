<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\Subscription;
use App\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * FR-NEW-06: disable peers whose subscription has lapsed; re-enable on renewal.
 * Runs every few minutes from the scheduler.
 */
class SweepExpiredSubscriptions extends Command
{
    protected $signature = 'mvpn:sweep-expired';
    protected $description = 'Disable peers for expired subscriptions and mark them expired';

    public function handle(): int
    {
        $expired = Subscription::where('status', 'active')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $sub) {
            $sub->update(['status' => 'expired']);

            $affectedNodes = $sub->peers()->where('status', 'active')->pluck('node_id')->unique();

            $sub->peers()->where('status', 'active')->update(['status' => 'disabled']);

            Node::whereIn('id', $affectedNodes)->get()->each->bumpPeerVersion();

            AuditLog::create([
                'actor' => 'system',
                'action' => 'subscription.expired',
                'target' => "subscription:{$sub->id}",
                'meta' => ['plan' => $sub->plan_code],
            ]);
        }

        $this->info("Expired {$expired->count()} subscription(s).");

        return self::SUCCESS;
    }
}
