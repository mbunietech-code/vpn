<?php

namespace App\Filament\Widgets;

use App\Models\TrafficSample;
use App\Services\TrafficRecorder;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * All customers' download speed per 5 minutes, stacked by kind of traffic:
 * when calls broke up, what else was running.
 */
class TrafficByCategoryChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected ?string $heading = 'Download speed by kind of traffic (Mbps, all customers)';

    protected ?string $description = 'Average per 5 minutes. A tall red/orange band at the time calls broke up means video or downloads were filling the line.';

    protected ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '6';

    private const COLORS = [
        'calls' => '#16A34A', 'video' => '#DC2626', 'downloads' => '#D97706', 'social' => '#DB2777',
        'messaging' => '#0891B2', 'web' => '#2563EB', 'other_udp' => '#7C3AED', 'unknown' => '#9CA3AF',
    ];

    protected function getFilters(): ?array
    {
        return ['1' => 'Last hour', '6' => 'Last 6 hours', '24' => 'Last 24 hours', '72' => 'Last 3 days'];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $hours = in_array($this->filter, ['1', '6', '24', '72'], true) ? (int) $this->filter : 6;
        $step = TrafficRecorder::BUCKET_SECONDS;
        $start = intdiv(now()->subHours($hours)->timestamp, $step) * $step;
        $end = intdiv(now()->timestamp, $step) * $step;

        $byBucket = TrafficSample::where('bucket_at', '>=', Carbon::createFromTimestamp($start))
            ->get(['bucket_at', 'bytes_up', 'bytes_down', 'categories'])
            ->groupBy(fn ($s) => $s->bucket_at->timestamp)
            ->map(fn ($rows) => TrafficRecorder::categoryTotals($rows));

        $labels = [];
        $series = array_fill_keys(array_keys(TrafficRecorder::CATEGORIES), []);
        for ($t = $start; $t <= $end; $t += $step) {
            $labels[] = Carbon::createFromTimestamp($t)->timezone(config('app.timezone'))->format($hours > 24 ? 'd/m H:i' : 'H:i');
            $cats = $byBucket->get($t, []);
            foreach (array_keys($series) as $cat) {
                $series[$cat][] = round(($cats[$cat][1] ?? 0) * 8 / $step / 1e6, 2);
            }
        }

        $datasets = [];
        foreach ($series as $cat => $data) {
            if (array_sum($data) <= 0) {
                continue;
            }
            $datasets[] = [
                'label' => TrafficRecorder::CATEGORIES[$cat],
                'data' => $data,
                'borderColor' => self::COLORS[$cat],
                'backgroundColor' => self::COLORS[$cat].'99',
                'fill' => true,
                'tension' => 0.25,
                'pointRadius' => 0,
                'borderWidth' => 1,
            ];
        }

        return ['datasets' => $datasets, 'labels' => $labels];
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'x' => ['grid' => ['display' => false], 'ticks' => ['maxTicksLimit' => 10]],
                'y' => ['beginAtZero' => true, 'stacked' => true],
            ],
        ];
    }
}
