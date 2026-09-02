<?php

namespace App\Filament\Resources\Plans\Tables;

use App\Models\Plan;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('code')->badge()->color('gray'),
                TextColumn::make('days')->label('Siku')->suffix(' d'),
                TextColumn::make('max_devices')->label('Vifaa'),
                TextColumn::make('price_cny_cents')->label('CNY')
                    ->formatStateUsing(fn ($s) => '¥' . number_format($s / 100, $s % 100 ? 2 : 0)),
                TextColumn::make('price_usd_cents')->label('USD')
                    ->formatStateUsing(fn ($s) => '$' . number_format($s / 100, 2)),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
