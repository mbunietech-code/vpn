<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Services\SubscriptionAdmin;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')->label('Customer')->searchable()->placeholder('—')
                    ->description(fn (User $r) => $r->phone),
                TextColumn::make('phone')->visibleFrom('md')->searchable()->placeholder('—'),
                IconColumn::make('is_admin')->visibleFrom('md')->label('Admin')->boolean(),
                TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'danger'),
                TextColumn::make('subscriptions_count')->visibleFrom('md')->label('Subs')->counts('subscriptions'),
                TextColumn::make('created_at')->visibleFrom('md')->since()->label('Joined'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'suspended' => 'Suspended']),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('suspend')
                        ->label('Suspend customer')
                        ->icon('heroicon-m-no-symbol')
                        ->color('danger')
                        ->visible(fn (User $r) => $r->status === 'active' && ! $r->is_admin)
                        ->requiresConfirmation()
                        ->modalDescription('Cuts all of this customer\'s VPN access within about 15 seconds and signs them out of the app.')
                        ->schema([TextInput::make('reason')->label('Reason (optional)')->maxLength(200)])
                        ->action(function (User $r, array $data) {
                            app(SubscriptionAdmin::class)->setUserStatus($r, 'suspended', 'admin:'.auth()->id(), $data['reason'] ?? null);
                            Notification::make()->success()->title('Customer suspended')->send();
                        }),
                    Action::make('restore')
                        ->label('Restore customer')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->visible(fn (User $r) => $r->status !== 'active')
                        ->requiresConfirmation()
                        ->action(function (User $r) {
                            app(SubscriptionAdmin::class)->setUserStatus($r, 'active', 'admin:'.auth()->id());
                            Notification::make()->success()->title('Customer restored')->send();
                        }),
                    EditAction::make(),
                    Action::make('delete')
                        ->label('Delete customer')
                        ->icon('heroicon-m-trash')
                        ->color('danger')
                        ->visible(fn (User $r) => ! $r->is_admin)
                        ->requiresConfirmation()
                        ->modalHeading('Delete this customer?')
                        ->modalDescription('Deletes the account with all its subscriptions, devices and payments (screenshots too). '
                            .'Any active VPN stops within about 15 seconds. This cannot be undone. '
                            .'To only stop access, use Suspend customer instead.')
                        ->modalSubmitActionLabel('Delete customer')
                        ->action(function (User $r) {
                            app(SubscriptionAdmin::class)->deleteCustomer($r, 'admin:'.auth()->id());
                            Notification::make()->success()->title('Customer deleted')->send();
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
                        ->modalHeading('Delete the selected customers?')
                        ->modalDescription('Deletes their accounts with all subscriptions, devices and payments. Active VPNs stop within about 15 seconds. Admin accounts are skipped. This cannot be undone.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $actor = 'admin:'.auth()->id();
                            $done = $records->filter(fn (User $u) => app(SubscriptionAdmin::class)->deleteCustomer($u, $actor))->count();
                            $skipped = $records->count() - $done;
                            Notification::make()->success()
                                ->title("Deleted {$done} customer(s)")
                                ->body($skipped ? "{$skipped} admin account(s) skipped." : null)
                                ->send();
                        }),
                ]),
            ]);
    }
}
