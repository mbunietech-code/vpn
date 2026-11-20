<?php

namespace App\Filament\Resources\Devices\Widgets;

use App\Models\Device;
use App\Models\TrafficSample;
use App\Services\TrafficRecorder;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

/** Last 24 hours of one device: data, speed now, peak speed, protocol. */
class DeviceTrafficStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '30s';

    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array|null
    {
        return ['@sm' => 2, '@lg' => 4];
    }

    protected function getStats(): array
    {
        /** @var Device|null $device */
        $device = $this->record;
        if (! $device) {
            return [];
        }

        $samples = app(TrafficRecorder::class)->deviceQuery($device, now()->subDay())->get();
        $recent = $samples->filter(fn (TrafficSample $s) => $s->updated_at?->gt(now()->subMinutes(3)));
        $latest = $samples->sortByDesc('updated_at')->first();
        $peak = $samples->sortByDesc('peak_down_mbps')->first();

        $protocol = $latest
            ? TrafficRecorder::protocolLabel($latest->protocol, $latest->inbound)
                .($latest->source_ip ? ' · '.TrafficRecorder::ipVersion($latest->source_ip) : '')
            : ($device->protocol ?: '—');

        return [
            Stat::make('Data, last 24 h', TrafficRecorder::bytes($samples->sum('bytes_down')).' ↓')
                ->description(TrafficRecorder::bytes($samples->sum('bytes_up')).' ↑ upload')
                ->icon('heroicon-o-arrows-up-down'),
            Stat::make('Speed now', $recent->isEmpty() ? 'Idle' : number_format($recent->sum('last_down_mbps'), 1).' Mbps ↓')
                ->description($recent->isEmpty()
                    ? ($latest ? 'last traffic '.$latest->updated_at->diffForHumans() : 'no traffic reported yet')
                    : number_format($recent->sum('last_up_mbps'), 1).' Mbps ↑ · average of the last minute')
                ->color($recent->isEmpty() ? 'gray' : 'success')
                ->icon('heroicon-o-bolt'),
            Stat::make('Peak speed, 24 h', $peak ? number_format($peak->peak_down_mbps, 1).' Mbps ↓' : '—')
                ->description($peak
                    ? 'at '.$peak->bucket_at->timezone(config('app.timezone'))->format('d/m H:i')
                        .' · '.number_format((float) $samples->max('peak_up_mbps'), 1).' Mbps ↑ max'
                    : 'no traffic reported yet')
                ->icon('heroicon-o-chart-bar'),
            Stat::make('Protocol', $protocol)
                ->description(trim(($device->last_seen_at ? 'app seen '.$device->last_seen_at->diffForHumans() : 'app never seen')
                    .($latest?->source_ip ? ' · from '.$latest->source_ip : '')))
                ->icon('heroicon-o-signal'),
        ];
    }
}
