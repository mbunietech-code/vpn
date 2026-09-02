<?php

namespace App\Filament\Resources\Nodes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('region')
                    ->searchable(),
                TextColumn::make('public_host')
                    ->searchable(),
                TextColumn::make('cdn_host')
                    ->searchable(),
                TextColumn::make('api_base')
                    ->searchable(),
                TextColumn::make('api_secret')
                    ->searchable(),
                TextColumn::make('reality_pubkey')
                    ->searchable(),
                TextColumn::make('reality_short_id')
                    ->searchable(),
                TextColumn::make('reality_sni')
                    ->searchable(),
                TextColumn::make('hysteria_port_range')
                    ->searchable(),
                TextColumn::make('hysteria_cert_sha256')
                    ->searchable(),
                TextColumn::make('capacity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->searchable(),
                TextColumn::make('peer_version')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_health_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
