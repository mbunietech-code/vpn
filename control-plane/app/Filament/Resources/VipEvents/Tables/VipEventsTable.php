<?php

namespace App\Filament\Resources\VipEvents\Tables;

use App\Filament\Resources\VipEvents\VipActions;
use App\Models\VipEvent;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VipEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('ends_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (VipEvent $r) => $r->priceLabel('cny').' per device · /vip/'.$r->slug),
                TextColumn::make('ends_at')
                    ->label('Access ends')
                    ->formatStateUsing(fn (VipEvent $r) => $r->endsLabel())
                    ->description(fn (VipEvent $r) => $r->ends_at?->diffForHumans())
                    ->color(fn (VipEvent $r) => $r->hasEnded() ? 'gray' : null)
                    ->sortable(),
                // A handful of events: plain per-row counts are fine.
                TextColumn::make('sold_passes')->label('Passes')->visibleFrom('md')
                    ->state(fn (VipEvent $r) => $r->passes()->where('status', 'active')->count()),
                TextColumn::make('sold_devices')->label('Devices')->visibleFrom('md')
                    ->state(fn (VipEvent $r) => (int) $r->passes()->where('status', 'active')->sum('devices')),
                IconColumn::make('sales_open')->label('Selling')->boolean(),
                IconColumn::make('access_enabled')->label('VPN on')->boolean(),
            ])
            ->recordActions([
                VipActions::qr(),
                ActionGroup::make([
                    VipActions::give(),
                    VipActions::toggleAccess(),
                    EditAction::make(),
                ]),
            ]);
    }
}
