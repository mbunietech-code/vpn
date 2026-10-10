<?php

namespace App\Filament\Resources\Devices\Tables;

use App\Models\Device;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->poll('30s')
            ->columns([
                TextColumn::make('subscription.user.email')->label('Mtumiaji')->searchable()
                    ->description(fn (Device $r) => $r->subscription?->user?->phone),
                TextColumn::make('name')->placeholder('—'),
                TextColumn::make('platform')->badge()->placeholder('—'),
                TextColumn::make('vpn_connected')->label('VPN sasa')
                    ->badge()
                    ->getStateUsing(fn (Device $r) => $r->isOnline() ? 'online' : 'offline')
                    ->formatStateUsing(fn ($s) => $s === 'online' ? 'Inatumia' : 'Haitumii')
                    ->color(fn ($s) => $s === 'online' ? 'success' : 'gray'),
                TextColumn::make('connected_at')->label('Tangu')->since()->placeholder('—')
                    ->getStateUsing(fn (Device $r) => $r->isOnline() ? $r->connected_at : null),
                TextColumn::make('protocol')->placeholder('—')->toggleable(),
                TextColumn::make('app_version')->label('App')->placeholder('—')->toggleable(),
                TextColumn::make('last_seen_at')->label('Mwisho')->since()->placeholder('kamwe'),
                TextColumn::make('revoked_at')->label('Hali')
                    ->badge()
                    ->formatStateUsing(fn ($s) => $s ? 'Imezuiwa' : 'Hai')
                    ->color(fn ($s) => $s ? 'danger' : 'success'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Zuia')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Device $r) => $r->revoked_at === null)
                    ->action(function (Device $r) {
                        $r->update(['revoked_at' => now()]);
                        Notification::make()->success()->title('Kifaa kimezuiwa')->send();
                    }),
            ]);
    }
}
