<?php

namespace App\Filament\Resources\Nodes\Tables;

use App\Models\Node;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Host and region under the name; health under status - keeps
                // the table inside the screen next to the sidebar.
                TextColumn::make('name')->weight('bold')->searchable()
                    ->description(fn ($record) => trim(strtoupper((string) $record->region).' · '.$record->public_host, ' ·')),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'online' => 'success',
                        'degraded' => 'warning',
                        'provisioning' => 'info',
                        default => 'danger',
                    })
                    ->description(fn ($record) => $record->last_health_at
                        ? 'health '.$record->last_health_at->diffForHumans()
                        : 'no health yet'),
                // Fills up first: the host's monthly data allowance. Peak is the
                // busiest 15 s the agent saw today (what customers feel).
                TextColumn::make('data_cycle')
                    ->label('Data this month')
                    ->getStateUsing(function (Node $record) {
                        $used = self::bytes($record->cycleBytes());
                        $percent = $record->quotaPercent();

                        return $percent === null
                            ? $used.' (set a quota)'
                            : sprintf('%s / %s · %s%%', $used, self::bytes($record->monthly_quota_gb * 1e9), $percent);
                    })
                    ->description(fn (Node $record) => 'peak today '.round($record->peakTxMbpsToday()).' Mbps')
                    ->color(fn (Node $record) => match (true) {
                        ($record->quotaPercent() ?? 0) >= (int) config('services.mvpn.quota_alert_percent', 80) => 'danger',
                        ($record->quotaPercent() ?? 0) >= 60 => 'warning',
                        default => null,
                    }),
                TextColumn::make('subscribers')
                    ->label('Subscribers')
                    ->getStateUsing(fn (Node $record) => $record->activeSubscriptionCount())
                    ->description(fn () => 'alert at '.config('services.mvpn.subscriber_alert', 80))
                    ->color(fn ($state) => $state >= (int) config('services.mvpn.subscriber_alert', 80) ? 'danger' : null),
                TextColumn::make('peers_count')->visibleFrom('md')
                    ->label('Peers')
                    ->counts('peers')
                    ->description(fn ($record) => 'config v'.$record->peer_version),
                TextColumn::make('priority')->visibleFrom('lg')
                    ->label('Priority')
                    ->sortable()
                    ->badge()
                    ->color('gray'),
            ])
            ->recordActions([EditAction::make()]);
    }

    private static function bytes(float|int $bytes): string
    {
        return $bytes >= 1e12
            ? round($bytes / 1e12, 2).' TB'
            : round($bytes / 1e9, 1).' GB';
    }
}
