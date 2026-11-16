<?php

namespace App\Filament\Resources\Devices\Widgets;

use App\Models\Device;
use App\Services\TrafficRecorder;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** One device's speed over time: average down/up per 5 minutes and the peak. */
class DeviceTrafficChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected ?string $heading = 'Speed over time (Mbps)';

    protected ?string $description = 'Average per 5 minutes, and the highest short peak inside each 5 minutes.';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    public ?string $filter = '24';

    protected function getFilters(): ?array
    {
        return ['6' => 'Last 6 hours', '24' => 'Last 24 hours', '72' => 'Last 3 days'];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        /** @var Device|null $device */
        $device = $this->record;
        $hours = in_array($this->filter, ['6', '24', '72'], true) ? (int) $this->filter : 24;
        $step = TrafficRecorder::BUCKET_SECONDS;
        $start = intdiv(now()->subHours($hours)->timestamp, $step) * $step;
        $end = intdiv(now()->timestamp, $step) * $step;

        $rows = $device
            ? app(TrafficRecorder::class)->deviceQuery($device, Carbon::createFromTimestamp($start))->get()
                ->groupBy(fn ($s) => $s->bucket_at->timestamp)
            : collect();

        $labels = $down = $up = $peak = [];
        for ($t = $start; $t <= $end; $t += $step) {
            $b = $rows->get($t, collect());
            $labels[] = Carbon::createFromTimestamp($t)->timezone(config('app.timezone'))->format($hours > 24 ? 'd/m H:i' : 'H:i');
            $down[] = round($b->sum('bytes_down') * 8 / $step / 1e6, 2);
            $up[] = round($b->sum('bytes_up') * 8 / $step / 1e6, 2);
            $peak[] = round((float) $b->max('peak_down_mbps'), 2);
        }

        return [
            'datasets' => [
                self::line('Download (avg)', $down, '#2563EB', true),
                self::line('Upload (avg)', $up, '#16A34A', true),
                self::line('Download peak', $peak, '#DC2626', false, true),
            ],
            'labels' => $labels,
        ];
    }

    /** @param  list<float>  $data */
    public static function line(string $label, array $data, string $color, bool $fill, bool $dashed = false): array
    {
        return [
            'label' => $label, 'data' => $data, 'borderColor' => $color,
            'backgroundColor' => $color.'26', 'fill' => $fill, 'tension' => 0.3,
            'pointRadius' => 0, 'pointHoverRadius' => 3, 'borderWidth' => $dashed ? 1.5 : 2,
            'borderDash' => $dashed ? [4, 3] : [],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'x' => ['grid' => ['display' => false], 'ticks' => ['maxTicksLimit' => 10]],
                'y' => ['beginAtZero' => true],
            ],
        ];
    }
}
