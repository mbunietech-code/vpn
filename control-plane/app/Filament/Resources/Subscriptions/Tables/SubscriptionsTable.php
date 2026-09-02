<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Models\Subscription;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('expires_at')
            ->columns([
                TextColumn::make('user.email')
                    ->description(fn (Subscription $r) => $r->user?->phone)
                    ->searchable(),
                TextColumn::make('plan_code')->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($s) => match ($s) {
                        'active' => 'success',
                        'pending' => 'gray',
                        'suspended' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('expires_at')
                    ->label('Inaisha')
                    ->dateTime('d M Y')
                    ->description(fn (Subscription $r) => $r->expires_at
                        ? $r->expires_at->diffForHumans()
                        : null)
                    ->color(fn (Subscription $r) => $r->expires_at && $r->expires_at->isBefore(now()->addDays(3))
                        ? 'warning' : null)
                    ->sortable(),
                TextColumn::make('devices_count')->label('Vifaa')->counts('devices'),
                TextColumn::make('data_used_mb')->label('Data')
                    ->formatStateUsing(fn ($s) => number_format($s) . ' MB'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active', 'pending' => 'Pending',
                    'expired' => 'Expired', 'suspended' => 'Suspended',
                ]),
            ])
            ->recordActions([EditAction::make()]);
    }
}
