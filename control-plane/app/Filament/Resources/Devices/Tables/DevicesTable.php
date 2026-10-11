<?php

namespace App\Filament\Resources\Devices\Tables;

use App\Models\Device;
use App\Services\DeviceAdmin;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
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
                    ->description(fn (Device $r) => trim(($r->model ?? '').' · '.($r->os_version ?? ''), ' ·')
                        ?: $r->subscription?->user?->phone),
                TextColumn::make('name')->visibleFrom('md')->placeholder('—'),
                TextColumn::make('platform')->visibleFrom('md')->badge()->placeholder('—'),
                TextColumn::make('vpn_connected')->label('VPN now')
                    ->badge()
                    ->getStateUsing(fn (Device $r) => $r->revoked_at ? 'removed' : ($r->isOnline() ? 'online' : 'offline'))
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => match ($state) {
                        'online' => 'success', 'removed' => 'danger', default => 'gray'
                    })
                    ->description(fn (Device $r) => $r->last_seen_at ? 'seen '.$r->last_seen_at->diffForHumans() : 'never seen'),
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
                ActionGroup::make([
                    Action::make('remove')
                        ->label('Remove device')
                        ->icon('heroicon-m-no-symbol')
                        ->color('danger')
                        ->visible(fn (Device $r) => $r->revoked_at === null)
                        ->requiresConfirmation()
                        ->modalHeading('Remove this device?')
                        ->modalDescription('Use this for a device you do not recognise. It is blocked for good and its VPN stops within a minute. '
                            .'The customer is signed out on all devices and their VPN keys are changed, so a copied config stops working. '
                            .'The real owner just signs in again with an email code.')
                        ->modalSubmitActionLabel('Remove device')
                        ->schema([
                            TextInput::make('reason')->label('Reason (optional)')->maxLength(200),
                        ])
                        ->action(function (Device $r, array $data) {
                            app(DeviceAdmin::class)->remove($r, auth()->user()?->email ?? 'admin', $data['reason'] ?? null);
                            Notification::make()->success()->title('Device removed')
                                ->body('Blocked, customer signed out, VPN keys changed.')->send();
                        }),
                    Action::make('allow')
                        ->label('Allow again')
                        ->icon('heroicon-m-arrow-uturn-left')
                        ->visible(fn (Device $r) => $r->revoked_at !== null)
                        ->requiresConfirmation()
                        ->modalDescription('The device may sign in and connect again.')
                        ->action(function (Device $r) {
                            $r->update(['revoked_at' => null]);
                            Notification::make()->success()->title('Device allowed again')->send();
                        }),
                ]),
            ]);
    }
}
