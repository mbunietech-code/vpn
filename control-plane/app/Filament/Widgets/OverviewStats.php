<?php

namespace App\Filament\Widgets;

use App\Models\Device;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Subscription;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $active = Subscription::where('status', 'active')
            ->where('expires_at', '>', now())->count();

        $pending = Invoice::whereIn('status', ['pending', 'pending_review'])->count();

        $revenueCents = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', now()->startOfMonth())
            ->get()
            ->sum(fn (Invoice $i) => $i->currency === 'cny'
                ? $i->amount_cents / 7.2   // rough CNY→USD for a single headline number
                : $i->amount_cents);

        $nodesOnline = Node::where('status', 'online')->count();
        $nodesTotal = Node::count();

        $onlineNow = Device::online()->whereNull('revoked_at')->count();
        $onlineUsers = Device::online()->whereNull('revoked_at')->distinct('subscription_id')->count('subscription_id');

        $lastRun = \Illuminate\Support\Facades\Cache::get('scheduler:last_run');
        $schedulerOk = $lastRun && \Illuminate\Support\Carbon::parse($lastRun)->gt(now()->subMinutes(5));

        $expiringSoon = Subscription::where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays(3)])->count();

        return [
            Stat::make('Active subscriptions', (string) $active)
                ->description($expiringSoon > 0 ? "$expiringSoon expire within 3 days" : 'All good')
                ->descriptionIcon($expiringSoon > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
                ->color($expiringSoon > 0 ? 'warning' : 'success'),

            Stat::make('Using the VPN now', (string) $onlineNow)
                ->description($onlineNow > 0 ? "$onlineNow devices · $onlineUsers customers" : 'Nobody connected')
                ->descriptionIcon('heroicon-m-signal')
                ->color($onlineNow > 0 ? 'success' : 'gray')
                ->url(\App\Filament\Resources\Devices\DeviceResource::getUrl()),

            Stat::make('Pending payments', (string) $pending)
                ->description($pending > 0 ? 'Need your approval' : 'None')
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->color($pending > 0 ? 'danger' : 'gray'),

            Stat::make('Revenue (this month)', '$' . number_format($revenueCents / 100, 0))
                ->description('Approximate, in USD')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Cron (scheduler)', $schedulerOk ? 'Running' : 'Not running!')
                ->description($lastRun
                    ? 'Last run ' . \Illuminate\Support\Carbon::parse($lastRun)->diffForHumans()
                    : 'Never ran — add the cron job in hPanel')
                ->descriptionIcon($schedulerOk ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($schedulerOk ? 'success' : 'danger'),

            Stat::make('Nodes online', "$nodesOnline / $nodesTotal")
                ->description($nodesOnline < $nodesTotal ? 'A node is down!' : 'All up')
                ->descriptionIcon($nodesOnline < $nodesTotal ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-server')
                ->color($nodesOnline < $nodesTotal ? 'danger' : 'success'),
        ];
    }
}
