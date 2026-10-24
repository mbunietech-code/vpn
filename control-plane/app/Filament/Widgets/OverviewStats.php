<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Resources\Nodes\NodeResource;
use App\Models\Device;
use App\Models\Node;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** Network health: who is connected, whether the nodes and the cron are up. */
class OverviewStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 6;

    protected ?string $pollingInterval = '30s';

    protected ?string $heading = 'Network';

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array|null
    {
        return ['@md' => 3, '!@sm' => 3];
    }

    protected function getStats(): array
    {
        $nodesOnline = Node::where('status', 'online')->count();
        $nodesTotal = Node::count();

        $onlineNow = Device::online()->whereNull('revoked_at')->count();
        $onlineUsers = Device::online()->whereNull('revoked_at')->distinct('subscription_id')->count('subscription_id');

        $lastRun = Cache::get('scheduler:last_run');
        $schedulerOk = $lastRun && Carbon::parse($lastRun)->gt(now()->subMinutes(5));

        return [
            Stat::make('Using the VPN now', (string) $onlineNow)
                ->icon('heroicon-o-signal')
                ->description($onlineNow > 0 ? "$onlineNow devices · $onlineUsers customers" : 'Nobody connected')
                ->color($onlineNow > 0 ? 'success' : 'gray')
                ->url(DeviceResource::getUrl())
                ->extraAttributes(['class' => 'mvpn-stat '.($onlineNow > 0 ? 'mvpn-stat-green' : 'mvpn-stat-gray')]),

            Stat::make('Nodes online', "$nodesOnline / $nodesTotal")
                ->icon('heroicon-o-server-stack')
                ->description($nodesOnline < $nodesTotal ? 'A node is down!' : 'All up')
                ->descriptionIcon($nodesOnline < $nodesTotal ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($nodesOnline < $nodesTotal ? 'danger' : 'success')
                ->url(NodeResource::getUrl())
                ->extraAttributes(['class' => 'mvpn-stat '.($nodesOnline < $nodesTotal ? 'mvpn-stat-red' : 'mvpn-stat-teal')]),

            Stat::make('Cron (scheduler)', $schedulerOk ? 'Running' : 'Not running!')
                ->icon('heroicon-o-clock')
                ->description($lastRun
                    ? 'Last run '.Carbon::parse($lastRun)->diffForHumans()
                    : 'Never ran — add the cron job in hPanel')
                ->descriptionIcon($schedulerOk ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($schedulerOk ? 'success' : 'danger')
                ->extraAttributes(['class' => 'mvpn-stat '.($schedulerOk ? 'mvpn-stat-green' : 'mvpn-stat-red')]),
        ];
    }
}
