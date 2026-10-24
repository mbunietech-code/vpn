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
                TextColumn::make('name')->weight('bold')
                    ->description(fn (Plan $r) => implode(' · ', $r->display())
                        . ($r->price_tzs_cents > 0 ? '' : ' · no TZS price')
                        . ' · ' . $r->days . ' days · ' . $r->max_devices . ' devices'),
                TextColumn::make('code')->visibleFrom('md')->badge()->color('gray'),
                TextColumn::make('days')->visibleFrom('md')->label('Days')->suffix(' d'),
                TextColumn::make('max_devices')->visibleFrom('md')->label('Devices'),
                TextColumn::make('price_cny_cents')->visibleFrom('md')->label('CNY')
                    ->formatStateUsing(fn ($state) => '¥' . number_format($state / 100, $state % 100 ? 2 : 0)),
                TextColumn::make('price_usd_cents')->visibleFrom('md')->label('USD')
                    ->formatStateUsing(fn ($state) => '$' . number_format($state / 100, 2)),
                // Shown on phones too: EduHub can only sell plans that have a TZS price.
                TextColumn::make('price_tzs_cents')->visibleFrom('md')->label('TZS')
                    ->formatStateUsing(fn ($state) => $state > 0 ? 'TSh ' . number_format($state / 100) : '—')
                    ->color(fn ($state) => $state > 0 ? null : 'danger')
                    ->tooltip(fn ($state) => $state > 0 ? null : 'No TZS price — cannot be sold through MbunieHub'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
