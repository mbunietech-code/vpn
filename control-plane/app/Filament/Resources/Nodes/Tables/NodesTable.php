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
                TextColumn::make('name')->weight('bold')->searchable(),
                TextColumn::make('region')->badge(),
                TextColumn::make('public_host')->color('gray')->copyable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($s) => match ($s) {
                        'online' => 'success',
                        'degraded' => 'warning',
                        'provisioning' => 'info',
                        default => 'danger',
                    }),
                TextColumn::make('peers_count')
                    ->label('Peers')
                    ->counts('peers'),
                TextColumn::make('peer_version')->label('v')->badge()->color('gray'),
                TextColumn::make('last_health_at')
                    ->label('Health')
                    ->since()
                    ->placeholder('kamwe')
                    ->color(fn ($state) => $state && $state->gt(now()->subMinutes(5)) ? 'success' : 'danger'),
            ])
            ->recordActions([EditAction::make()]);
    }
}
