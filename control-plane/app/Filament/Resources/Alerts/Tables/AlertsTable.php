<?php

namespace App\Filament\Resources\Alerts\Tables;

use App\Models\Alert;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('severity')->visibleFrom('md')->badge()
                    ->color(fn ($state) => match ($state) {
                        'critical' => 'danger', 'warn' => 'warning', default => 'gray',
                    }),
                TextColumn::make('source')->visibleFrom('md')->badge()->color('gray'),
                TextColumn::make('title')->wrap()->searchable()
                    ->description(fn (\App\Models\Alert $r) => $r->severity . ' · ' . $r->created_at?->diffForHumans()),
                TextColumn::make('ai_summary')->visibleFrom('md')->label('AI')->wrap()->placeholder('—')->toggleable(),
                TextColumn::make('acknowledged_at')->visibleFrom('md')->label('Resolved')
                    ->since()->placeholder('—')
                    ->badge()->color(fn ($state) => $state ? 'success' : 'danger'),
                TextColumn::make('created_at')->visibleFrom('md')->since(),
            ])
            ->filters([
                TernaryFilter::make('acknowledged_at')
                    ->label('Open')
                    ->nullable()
                    ->trueLabel('All')
                    ->falseLabel('Open only')
                    ->queries(
                        true: fn ($q) => $q,
                        false: fn ($q) => $q->whereNull('acknowledged_at'),
                        blank: fn ($q) => $q->whereNull('acknowledged_at'),
                    ),
            ])
            ->recordActions([
                Action::make('ack')
                    ->label('Mark resolved')
                    ->icon('heroicon-m-check')
                    ->visible(fn (Alert $r) => $r->acknowledged_at === null)
                    ->action(function (Alert $r) {
                        $r->update(['acknowledged_at' => now(), 'acknowledged_by' => 'admin:' . auth()->id()]);
                        Notification::make()->success()->title('Resolved')->send();
                    }),
                ViewAction::make(),
            ]);
    }
}
