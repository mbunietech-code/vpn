<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Resources\Devices\Widgets\DeviceTrafficChart;
use App\Filament\Resources\Devices\Widgets\DeviceTrafficStats;
use App\Models\Device;
use App\Models\TrafficSample;
use App\Services\TrafficRecorder;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Admin › Devices › (a device) › Traffic: how much it moves, how fast, over
 * which protocol, and what kind of traffic (calls, video, downloads…).
 */
class DeviceTraffic extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DeviceResource::class;

    protected string $view = 'filament.traffic.device';

    /** Hours shown in the breakdown tables. */
    public string $hours = '24';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Device $d */
        $d = $this->getRecord();
        $who = $d->subscription?->user?->email ?? $d->subscription?->user?->phone ?? 'Device';

        return 'Traffic · '.$who;
    }

    public function getSubheading(): ?string
    {
        /** @var Device $d */
        $d = $this->getRecord();

        return trim(implode(' · ', array_filter([
            $d->name, $d->model, $d->platform ? trim(ucfirst($d->platform).' '.$d->app_version) : null,
        ]))) ?: null;
    }

    protected function getHeaderWidgets(): array
    {
        return [DeviceTrafficStats::class, DeviceTrafficChart::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var Device $device */
        $device = $this->getRecord();
        $hours = in_array($this->hours, ['1', '6', '24', '72'], true) ? (int) $this->hours : 24;
        $samples = app(TrafficRecorder::class)->deviceQuery($device, now()->subHours($hours))->get();

        $sessions = $samples
            ->groupBy(fn (TrafficSample $s) => $s->source_ip.'|'.$s->protocol.'|'.$s->inbound)
            ->map(fn ($rows) => [
                'ip' => $rows->first()->source_ip,
                'ip_version' => TrafficRecorder::ipVersion($rows->first()->source_ip),
                'protocol' => TrafficRecorder::protocolLabel($rows->first()->protocol, $rows->first()->inbound),
                'up' => (int) $rows->sum('bytes_up'),
                'down' => (int) $rows->sum('bytes_down'),
                'peak' => (float) $rows->max('peak_down_mbps'),
                'shared' => $rows->contains(fn ($s) => $s->device_id === null),
                'last' => $rows->sortByDesc('updated_at')->first()->updated_at,
            ])
            ->sortByDesc(fn ($r) => $r['up'] + $r['down'])
            ->values();

        $otherDevices = Device::where('subscription_id', $device->subscription_id)
            ->whereNull('revoked_at')->whereKeyNot($device->id)->count();

        return [
            'device' => $device,
            'categories' => TrafficRecorder::categoryTotals($samples),
            'sessions' => $sessions,
            'total' => (int) $samples->sum('bytes_up') + (int) $samples->sum('bytes_down'),
            'otherDevices' => $otherDevices,
            'hasShared' => $sessions->contains('shared', true),
        ];
    }
}
