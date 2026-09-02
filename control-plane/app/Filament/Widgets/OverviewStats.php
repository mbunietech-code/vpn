<?php

namespace App\Filament\Widgets;

use App\Models\Invoice;
use App\Models\Node;
use App\Models\Subscription;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStats extends StatsOverviewWidget
{
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

        $expiringSoon = Subscription::where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays(3)])->count();

        return [
            Stat::make('Subscriptions hai', (string) $active)
                ->description($expiringSoon > 0 ? "$expiringSoon zinaisha ndani ya siku 3" : 'Zote sawa')
                ->descriptionIcon($expiringSoon > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
                ->color($expiringSoon > 0 ? 'warning' : 'success'),

            Stat::make('Malipo yanayosubiri', (string) $pending)
                ->description($pending > 0 ? 'Yanahitaji idhini yako' : 'Hakuna')
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->color($pending > 0 ? 'danger' : 'gray'),

            Stat::make('Mapato (mwezi huu)', '$' . number_format($revenueCents / 100, 0))
                ->description('Makadirio ya USD')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Nodes online', "$nodesOnline / $nodesTotal")
                ->description($nodesOnline < $nodesTotal ? 'Kuna node chini!' : 'Zote hai')
                ->descriptionIcon($nodesOnline < $nodesTotal ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-server')
                ->color($nodesOnline < $nodesTotal ? 'danger' : 'success'),
        ];
    }
}
