<?php

namespace App\Filament\Resources\Devices\Tables;

use App\Models\Device;
use App\Services\DeviceAdmin;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class DevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->poll('30s')
            ->columns([
                // Few, dense columns so the table never scrolls sideways:
                // details sit in each cell's second line.
                TextColumn::make('subscription.user.email')->label('Customer')->searchable()
                    ->description(fn (Device $r) => trim(($r->model ?? '').' · '.($r->os_version ?? ''), ' ·')
                        ?: $r->subscription?->user?->phone),
                TextColumn::make('vpn_connected')->label('VPN now')
                    ->badge()
                    ->getStateUsing(fn (Device $r) => $r->revoked_at ? 'removed' : ($r->isOnline() ? 'online' : 'offline'))
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => match ($state) {
                        'online' => 'success', 'removed' => 'danger', default => 'gray'
                    })
                    ->description(fn (Device $r) => match (true) {
                        $r->revoked_at !== null => 'removed '.$r->revoked_at->diffForHumans(),
                        $r->isOnline() && $r->connected_at !== null => 'connected '.$r->connected_at->diffForHumans(),
                        $r->last_seen_at !== null => 'seen '.$r->last_seen_at->diffForHumans(),
                        default => 'never seen',
                    }),
                TextColumn::make('platform')->label('App')->visibleFrom('md')
                    ->badge()->placeholder('—')
                    ->formatStateUsing(fn ($state, Device $r) => ucfirst((string) $state).($r->app_version ? ' '.$r->app_version : ''))
                    ->description(fn (Device $r) => $r->protocol ? 'protocol '.$r->protocol : null),
                TextColumn::make('last_error')->visibleFrom('lg')->label('Last error')
                    ->placeholder('—')->color('danger')->wrap()->limit(90)
                    ->tooltip(fn (Device $r) => $r->last_error)
                    ->description(fn (Device $r) => $r->last_error_at?->diffForHumans()),
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
                    Action::make('delete')
                        ->label('Delete')
                        ->icon('heroicon-m-trash')
                        ->color('gray')
                        ->visible(fn (Device $r) => $r->revoked_at === null)
                        ->requiresConfirmation()
                        ->modalHeading('Delete this device?')
                        ->modalDescription('Takes it off the list and frees a place in the customer\'s device limit. '
                            .'It is not blocked: if the app is still installed, it appears again the next time it connects. '
                            .'To block a device, use Remove device.')
                        ->modalSubmitActionLabel('Delete')
                        ->action(function (Device $r) {
                            app(DeviceAdmin::class)->delete($r, auth()->user()?->email ?? 'admin');
                            Notification::make()->success()->title('Device deleted')->send();
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('delete')
                        ->label('Delete selected')
                        ->icon('heroicon-m-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete the selected devices?')
                        ->modalDescription('They come off the list and free places in the device limits. Removed (blocked) devices are kept so they stay blocked.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $actor = auth()->user()?->email ?? 'admin';
                            $done = $records->filter(fn (Device $d) => app(DeviceAdmin::class)->delete($d, $actor))->count();
                            $kept = $records->count() - $done;
                            Notification::make()->success()
                                ->title("Deleted {$done} device(s)")
                                ->body($kept ? "{$kept} removed (blocked) device(s) kept." : null)
                                ->send();
                        }),
                ]),
            ]);
    }
}
