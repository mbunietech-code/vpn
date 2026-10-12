<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * Older apps made a new device id on every reinstall, so one phone showed up
 * several times and ate into the customer's device limit. Keep one row per
 * phone; removed (revoked) devices are never touched.
 */
class DedupeDevices extends Command
{
    protected $signature = 'mvpn:dedupe-devices {--dry-run : List what would be removed}';

    protected $description = 'Remove duplicate and stale device rows (one row per phone/PC)';

    public function handle(): int
    {
        $removed = 0;

        Subscription::with('devices')->chunkById(200, function ($subs) use (&$removed) {
            foreach ($subs as $sub) {
                $live = $sub->devices->whereNull('revoked_at')->sortByDesc('last_seen_at')->values();
                $drop = collect();

                // Same platform + model on one subscription: the same phone.
                $live->filter(fn (Device $d) => filled($d->model))
                    ->groupBy(fn (Device $d) => $d->platform.'|'.$d->model)
                    ->each(fn ($rows) => $drop->push(...$rows->slice(1)));

                // Rows from apps too old to report a model, quiet for 6 hours,
                // with a newer row on the same platform: leftovers of a reinstall.
                $live->filter(fn (Device $d) => blank($d->model)
                    && ($d->last_seen_at === null || $d->last_seen_at->lt(now()->subHours(6)))
                    && $live->contains(fn (Device $o) => $o->id !== $d->id
                        && $o->platform === $d->platform
                        && $o->last_seen_at?->gt($d->last_seen_at ?? now()->subYears(10))))
                    ->each(fn (Device $d) => $drop->push($d));

                // Not seen for 60 days.
                $live->filter(fn (Device $d) => $d->last_seen_at?->lt(now()->subDays(60)))
                    ->each(fn (Device $d) => $drop->push($d));

                foreach ($drop->unique('id') as $d) {
                    $this->line(sprintf('sub %d: device %d %s %s (seen %s)', $sub->id, $d->id, $d->platform, $d->model ?: '-', $d->last_seen_at ?? 'never'));
                    if (! $this->option('dry-run')) {
                        $d->delete();
                    }
                    $removed++;
                }
            }
        });

        $this->info(($this->option('dry-run') ? 'Would remove ' : 'Removed ').$removed.' duplicate device row(s).');

        return self::SUCCESS;
    }
}
