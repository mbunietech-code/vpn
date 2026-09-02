<?php

namespace App\Console\Commands;

use App\Services\AlertAnalyzer;
use Illuminate\Console\Command;

class ScanAnomalies extends Command
{
    protected $signature = 'mvpn:scan-anomalies';
    protected $description = 'Rule-based + AI anomaly scan across nodes and system state';

    public function handle(AlertAnalyzer $analyzer): int
    {
        $analyzer->scan();
        $this->info('Anomaly scan complete.');

        return self::SUCCESS;
    }
}
