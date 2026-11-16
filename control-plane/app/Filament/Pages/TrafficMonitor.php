<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\TrafficByCategoryChart;
use App\Models\TrafficSample;
use App\Services\TrafficRecorder;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Admin › Network › Traffic: which devices and which kinds of traffic load
 * the node right now and over the last hours, to find what hurts calls.
 */
class TrafficMonitor extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Network';

    protected static ?int $navigationSort = 35;

    protected static ?string $navigationLabel = 'Traffic';

    protected static ?string $title = 'Traffic: who uses the bandwidth';

    protected static ?string $slug = 'traffic';

    protected string $view = 'filament.traffic.monitor';

    /** Minutes covered by the ranking. */
    public string $minutes = '60';

    public const WINDOWS = ['15' => 'Last 15 minutes', '60' => 'Last hour', '360' => 'Last 6 hours', '1440' => 'Last 24 hours', '4320' => 'Last 3 days'];

    protected function getHeaderWidgets(): array
    {
        return [TrafficByCategoryChart::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $minutes = array_key_exists($this->minutes, self::WINDOWS) ? (int) $this->minutes : 60;
        // Bucket start times: include the bucket the window starts in.
        $since = now()->subMinutes($minutes)->subSeconds(TrafficRecorder::BUCKET_SECONDS);
        $traffic = app(TrafficRecorder::class);

        $samples = TrafficSample::where('bucket_at', '>=', $since)->get(['bytes_up', 'bytes_down', 'categories', 'last_down_mbps', 'last_up_mbps', 'updated_at']);
        $recent = $samples->filter(fn ($s) => $s->updated_at?->gt(now()->subMinutes(3)));

        return [
            'consumers' => $traffic->topConsumers($since),
            'categories' => TrafficRecorder::categoryTotals($samples),
            'totalDown' => (int) $samples->sum('bytes_down'),
            'totalUp' => (int) $samples->sum('bytes_up'),
            'nowDown' => (float) $recent->sum('last_down_mbps'),
            'nowUp' => (float) $recent->sum('last_up_mbps'),
            'windowLabel' => self::WINDOWS[(string) $minutes],
        ];
    }
}
