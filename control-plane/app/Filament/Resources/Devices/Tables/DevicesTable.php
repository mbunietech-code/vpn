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
                // Phone model + OS under the customer, so phones show Customer + VPN now.
                TextColumn::make('subscription.user.email')->label('Customer')->searchable()
                    ->description(fn (Device $r) => trim(($r->model ?? '') . ' · ' . ($r->os_version ?? ''), ' ·')
                        ?: $r->subscription?->user?->phone),
                TextColumn::make('name')->visibleFrom('md')->placeholder('—'),
                TextColumn::make('platform')->visibleFrom('md')->badge()->placeholder('—'),
                TextColumn::make('vpn_connected')->label('VPN now')
                    ->badge()
                    ->getStateUsing(fn (Device $r) => $r->isOnline() ? 'online' : 'offline')
                    ->formatStateUsing(fn ($state) => $state === 'online' ? 'Online' : 'Offline')
                    ->color(fn ($state) => $state === 'online' ? 'success' : 'gray')
                    ->description(fn (Device $r) => $r->last_seen_at ? 'seen ' . $r->last_seen_at->diffForHumans() : 'never seen'),
                TextColumn::make('connected_at')->visibleFrom('md')->label('Connected')->since()->placeholder('—')
                    ->getStateUsing(fn (Device $r) => $r->isOnline() ? $r->connected_at : null),
                TextColumn::make('protocol')->visibleFrom('md')->placeholder('—')->toggleable(),
                TextColumn::make('app_version')->visibleFrom('md')->label('App')->placeholder('—')->toggleable(),
                TextColumn::make('last_error')->visibleFrom('md')->label('Last error')
                    ->placeholder('—')->color('danger')->wrap()->limit(160)
                    ->tooltip(fn (Device $r) => $r->last_error)
                    ->description(fn (Device $r) => $r->last_error_at?->diffForHumans()),
                TextColumn::make('last_seen_at')->visibleFrom('md')->label('Last seen')->since()->placeholder('never'),
                TextColumn::make('revoked_at')->visibleFrom('md')->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Revoked' : 'Active')
                    ->color(fn ($state) => $state ? 'danger' : 'success'),
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
