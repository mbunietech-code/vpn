<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RevenueSummary;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected ?string $heading = 'Sales';

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array|null
    {
        // Two by two on tablets and narrow windows, one row on wide screens.
        return ['@md' => 2, '@4xl' => 4, '!@sm' => 2, '!@lg' => 4];
    }

    protected function getStats(): array
    {
        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        $last7 = RevenueSummary::paid($today->copy()->subDays(6));
        $daily = collect(range(6, 0))->map(fn ($d) => round(RevenueSummary::usd(
            $last7->filter(fn (Invoice $i) => $i->paid_at->isSameDay($today->copy()->subDays($d)))
        ), 2))->all();
        $todayPaid = $last7->filter(fn (Invoice $i) => $i->paid_at->gte($today));

        $thisMonth = RevenueSummary::paid($month);
        $thisMonthUsd = RevenueSummary::usd($thisMonth);
        // Same number of days of last month, so mid-month is a fair comparison.
        $lastMonthStart = $month->copy()->subMonthNoOverflow();
        $lastMonthUsd = RevenueSummary::usd(RevenueSummary::paid(
            $lastMonthStart,
            $lastMonthStart->copy()->addSeconds((int) $month->diffInSeconds(now())),
        ));
        $change = $lastMonthUsd > 0 ? (int) round(($thisMonthUsd - $lastMonthUsd) / $lastMonthUsd * 100) : null;

        $newCustomers = User::where('is_admin', false)->where('created_at', '>=', $month)->count();
        $newToday = User::where('is_admin', false)->where('created_at', '>=', $today)->count();
        $buyers = $thisMonth->pluck('user_id')->unique()->count();

        $active = Subscription::where('status', 'active')->where('expires_at', '>', now())->count();
        $expiring = Subscription::where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays(7)])->count();

        return [
            Stat::make('Today', '≈ $'.number_format(RevenueSummary::usd($todayPaid), 0))
                ->icon('heroicon-o-banknotes')
                ->description(RevenueSummary::perCurrency($todayPaid) ?: 'No payments yet today')
                ->chart($daily)
                ->chartColor('success')
                ->extraAttributes(['class' => 'mvpn-stat mvpn-stat-green']),

            Stat::make('This month', '≈ $'.number_format($thisMonthUsd, 0))
                ->icon('heroicon-o-chart-bar')
                ->description(match (true) {
                    $change === null => RevenueSummary::perCurrency($thisMonth) ?: 'No payments yet',
                    $change >= 0 => "+$change% vs last month",
                    default => "$change% vs last month",
                })
                ->descriptionIcon($change === null ? null : ($change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'))
                ->color($change === null ? 'gray' : ($change >= 0 ? 'success' : 'danger'))
                ->extraAttributes(['class' => 'mvpn-stat mvpn-stat-blue']),

            Stat::make('New customers', (string) $newCustomers)
                ->icon('heroicon-o-user-plus')
                ->description(($newToday > 0 ? "$newToday today · " : '')."$buyers paid this month")
                ->url(UserResource::getUrl('index'))
                ->extraAttributes(['class' => 'mvpn-stat mvpn-stat-violet']),

            Stat::make('Active subscriptions', (string) $active)
                ->icon('heroicon-o-check-badge')
                ->description($expiring > 0 ? "$expiring expire within 7 days" : 'None expiring this week')
                ->descriptionIcon($expiring > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
                ->color($expiring > 0 ? 'warning' : 'success')
                ->url(SubscriptionResource::getUrl('index'))
                ->extraAttributes(['class' => 'mvpn-stat mvpn-stat-teal']),
        ];
    }
}
