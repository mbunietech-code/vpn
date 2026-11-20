<?php

namespace App\Console\Commands;

use App\Services\TrafficRecorder;
use Illuminate\Console\Command;

/** Per-customer traffic is kept only a few days (TrafficRecorder::RETENTION_HOURS). */
class PruneTraffic extends Command
{
    protected $signature = 'mvpn:prune-traffic';

    protected $description = 'Delete per-customer traffic samples older than the retention window';

    public function handle(TrafficRecorder $traffic): int
    {
        $this->info('Deleted '.$traffic->prune().' old traffic sample(s).');

        return self::SUCCESS;
    }
}
