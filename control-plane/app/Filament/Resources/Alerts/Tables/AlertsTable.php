<?php

namespace App\Filament\Resources\Alerts\Tables;

use App\Models\Alert;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class AlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('30s') // new alerts show up without a reload
            ->recordClasses(fn (Alert $r) => 'mvpn-alert mvpn-alert-'.($r->acknowledged_at ? 'resolved' : $r->severity))
            ->emptyStateIcon('heroicon-o-check-badge')
            ->emptyStateHeading('No alerts here')
            ->emptyStateDescription('All quiet. New problems show up here and are emailed to the admins.')
            ->columns([
                IconColumn::make('severity')
                    ->label('')
                    ->icon(fn (string $state) => match ($state) {
                        'critical' => 'heroicon-s-exclamation-circle',
                        'warn' => 'heroicon-s-exclamation-triangle',
                        default => 'heroicon-s-information-circle',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'critical' => 'danger', 'warn' => 'warning', default => 'info',
                    })
                    ->tooltip(fn (string $state) => ucfirst($state === 'warn' ? 'warning' : $state))
                    ->grow(false),
                TextColumn::make('title')
                    ->weight('semibold')
                    ->wrap()
                    ->searchable(['title', 'body'])
                    ->description(fn (Alert $r) => $r->ai_summary ?: str($r->body)->limit(160)->toString()),
                TextColumn::make('node.name')
                    ->label('Node')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'node.health' => 'Node health',
                        'node.sync' => 'Peer sync',
                        'webhook' => 'Payment',
                        'node.security' => 'Server security',
                        default => $state,
                    })
                    ->visibleFrom('lg'),
                TextColumn::make('acknowledged_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Alert $r) => $r->acknowledged_at ? 'Resolved' : 'Open')
                    ->color(fn (Alert $r) => $r->acknowledged_at ? 'success' : ($r->severity === 'critical' ? 'danger' : 'warning'))
                    ->tooltip(fn (Alert $r) => $r->acknowledged_at ? 'Resolved '.$r->acknowledged_at->diffForHumans() : null)
                    ->visibleFrom('sm'),
                TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->dateTimeTooltip('d M Y H:i')
                    ->sortable()
                    ->color('gray'),
            ])
            ->filters([
                SelectFilter::make('severity')->options([
                    'critical' => 'Critical',
                    'warn' => 'Warning',
                    'info' => 'Info',
                ]),
                SelectFilter::make('source')->options([
                    'node.health' => 'Node health',
                    'node.sync' => 'Peer sync',
                    'webhook' => 'Payment',
                    'node.security' => 'Server security',
                ]),
                // Plain options on node_id: the relationship() version crashed the page (500).
                SelectFilter::make('node_id')
                    ->label('Node')
                    ->options(fn () => \App\Models\Node::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('ack')
                    ->label('Resolve')
                    ->icon('heroicon-m-check')
                    ->color('success')
                    ->button()
                    ->size('sm')
                    ->visible(fn (Alert $r) => $r->acknowledged_at === null)
                    ->action(function (Alert $r) {
                        $r->update(['acknowledged_at' => now(), 'acknowledged_by' => 'admin:'.auth()->id()]);
                        Notification::make()->success()->title('Resolved')->send();
                    }),
                Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (Alert $r) => $r->acknowledged_at !== null)
                    ->action(fn (Alert $r) => $r->update(['acknowledged_at' => null, 'acknowledged_by' => null])),
                ViewAction::make()->label('Details')->modalWidth('2xl'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('resolveSelected')
                        ->label('Resolve selected')
                        ->icon('heroicon-m-check')
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $n = 0;
                            foreach ($records as $r) {
                                if ($r->acknowledged_at === null) {
                                    $r->update(['acknowledged_at' => now(), 'acknowledged_by' => 'admin:'.auth()->id()]);
                                    $n++;
                                }
                            }
                            Notification::make()->success()->title("$n resolved")->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
