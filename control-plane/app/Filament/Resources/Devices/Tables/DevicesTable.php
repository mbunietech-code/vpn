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
                TextColumn::make('subscription.user.email')->label('Customer')->searchable()
                    ->description(fn (Device $r) => $r->subscription?->user?->phone),
                TextColumn::make('name')->placeholder('—'),
                TextColumn::make('platform')->badge()->placeholder('—')
                    ->description(fn (Device $r) => trim(($r->model ?? '') . ' ' . ($r->os_version ?? '')) ?: null),
                TextColumn::make('vpn_connected')->label('VPN now')
                    ->badge()
                    ->getStateUsing(fn (Device $r) => $r->isOnline() ? 'online' : 'offline')
                    ->formatStateUsing(fn ($s) => $s === 'online' ? 'Online' : 'Offline')
                    ->color(fn ($s) => $s === 'online' ? 'success' : 'gray'),
                TextColumn::make('connected_at')->label('Connected')->since()->placeholder('—')
                    ->getStateUsing(fn (Device $r) => $r->isOnline() ? $r->connected_at : null),
                TextColumn::make('protocol')->placeholder('—')->toggleable(),
                TextColumn::make('app_version')->label('App')->placeholder('—')->toggleable(),
                TextColumn::make('last_error')->label('Last error')
                    ->placeholder('—')->color('danger')->wrap()->limit(160)
                    ->tooltip(fn (Device $r) => $r->last_error)
                    ->description(fn (Device $r) => $r->last_error_at?->diffForHumans()),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('never'),
                TextColumn::make('revoked_at')->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($s) => $s ? 'Revoked' : 'Active')
                    ->color(fn ($s) => $s ? 'danger' : 'success'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Device $r) => $r->revoked_at === null)
                    ->action(function (Device $r) {
                        $r->update(['revoked_at' => now()]);
                        Notification::make()->success()->title('Device revoked')->send();
                    }),
            ]);
    }
}
