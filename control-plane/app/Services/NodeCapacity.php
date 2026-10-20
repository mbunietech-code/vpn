<?php

namespace App\Services;

use App\Mail\NodeCapacityMail;
use App\Models\Alert;
use App\Models\Node;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Warns the admins when a node fills up - its monthly data quota or its
 * subscriber count - while there is still time to add a server. Each alert
 * is raised once (per quota cycle for data) and emailed when first raised.
 */
class NodeCapacity
{
    public function __construct(private PaymentAlerts $recipients, private Mailer $mailer) {}

    public function check(Node $node): void
    {
        $percent = $node->quotaPercent();
        $quotaAt = (int) config('services.mvpn.quota_alert_percent', 80);
        if ($percent !== null && $percent >= $quotaAt) {
            $cycle = $node->quotaCycleStart()->format('Y-m-d');
            $this->raise($node, 'node.quota',
                "{$node->name} used {$quotaAt}% of its monthly data (cycle from {$cycle})",
                sprintf('%s has moved %.2f TB of its %.2f TB monthly allowance (%.1f%%). Past the allowance the host may slow the server down.',
                    $node->name, $node->cycleBytes() / 1e12, $node->monthly_quota_gb / 1000, $percent),
                ['percent' => $percent, 'quota_gb' => $node->monthly_quota_gb, 'cycle_start' => $cycle]);
        }

        $subsAt = (int) config('services.mvpn.subscriber_alert', 80);
        $subs = $node->activeSubscriptionCount();
        if ($subsAt > 0 && $subs >= $subsAt) {
            $this->raise($node, 'node.subscribers',
                "{$node->name} has {$subsAt}+ active subscriptions",
                "{$node->name} now serves {$subs} active subscriptions (alert at {$subsAt}). Each can use two devices.",
                ['subscriptions' => $subs, 'threshold' => $subsAt]);
        }
    }

    private function raise(Node $node, string $source, string $title, string $body, array $context): void
    {
        $alert = Alert::firstOrCreate(
            ['source' => $source, 'node_id' => $node->id, 'title' => $title, 'acknowledged_at' => null],
            ['severity' => 'warning', 'body' => $body, 'context' => $context],
        );
        if (! $alert->wasRecentlyCreated) {
            return;
        }

        foreach ($this->recipients->recipients() as $to) {
            try {
                $this->mailer->send($to, new NodeCapacityMail($alert));
            } catch (Throwable $e) {
                // An alert email bouncing must never break the agent's health report.
                Log::warning('Node capacity email failed', ['to' => $to, 'alert' => $alert->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
