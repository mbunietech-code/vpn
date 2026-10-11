<?php

namespace App\Filament\Resources\Peers\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PeersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subscription.user.email')->label('Customer')->searchable(),
                TextColumn::make('node.name')->visibleFrom('md')->label('Node')->badge(),
                TextColumn::make('protocol')->badge()->color('gray'),
                TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('bytes_down')->visibleFrom('md')->label('Down')
                    ->formatStateUsing(fn ($state) => number_format($state / 1048576, 1) . ' MB'),
                TextColumn::make('updated_at')->visibleFrom('md')->since(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'disabled' => 'Disabled']),
                SelectFilter::make('protocol')->options([
                    'vless-reality' => 'VLESS-REALITY', 'hysteria2' => 'Hysteria2',
                ]),
            ]);
    }
}
