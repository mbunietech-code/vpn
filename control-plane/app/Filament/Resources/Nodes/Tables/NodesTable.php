<?php

namespace App\Filament\Resources\Nodes\Tables;

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
}
