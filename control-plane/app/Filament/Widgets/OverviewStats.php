<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Devices\DeviceResource;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Subscription;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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

        $paidThisMonth = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', now()->startOfMonth())
            ->get();
        $revenueCents = $paidThisMonth->sum(fn (Invoice $i) => $i->usdCents());
        // What was actually taken, per currency: "¥630 + TSh 3,900".
        $revenueParts = $paidThisMonth->groupBy('currency')->map(function ($rows, $cur) {
            $amount = $rows->sum('amount_cents') / 100;

            return match ($cur) {
                'usd' => '$'.number_format($amount, 2),
                'cny' => '¥'.number_format($amount, $amount == floor($amount) ? 0 : 2),
                'tzs' => 'TSh '.number_format($amount),
                default => strtoupper($cur).' '.number_format($amount, 2),
            };
        })->values()->implode(' + ');

        $nodesOnline = Node::where('status', 'online')->count();
        $nodesTotal = Node::count();

        $onlineNow = Device::online()->whereNull('revoked_at')->count();
        $onlineUsers = Device::online()->whereNull('revoked_at')->distinct('subscription_id')->count('subscription_id');

        $lastRun = Cache::get('scheduler:last_run');
        $schedulerOk = $lastRun && Carbon::parse($lastRun)->gt(now()->subMinutes(5));

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
                ->url(DeviceResource::getUrl()),

            Stat::make('Pending payments', (string) $pending)
                ->description($pending > 0 ? 'Need your approval' : 'None')
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->color($pending > 0 ? 'danger' : 'gray'),

            Stat::make('Revenue (this month)', '≈ $'.number_format($revenueCents / 100, 0))
                ->description($revenueParts !== '' ? $revenueParts : 'No payments yet')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Cron (scheduler)', $schedulerOk ? 'Running' : 'Not running!')
                ->description($lastRun
                    ? 'Last run '.Carbon::parse($lastRun)->diffForHumans()
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
