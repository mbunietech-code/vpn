<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Services\SubscriptionAdmin;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
                            app(SubscriptionAdmin::class)->setUserStatus($r, 'suspended', 'admin:' . auth()->id(), $data['reason'] ?? null);
                            Notification::make()->success()->title('Customer suspended')->send();
                        }),
                    Action::make('restore')
                        ->label('Restore customer')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->visible(fn (User $r) => $r->status !== 'active')
                        ->requiresConfirmation()
                        ->action(function (User $r) {
                            app(SubscriptionAdmin::class)->setUserStatus($r, 'active', 'admin:' . auth()->id());
                            Notification::make()->success()->title('Customer restored')->send();
                        }),
                    EditAction::make(),
                ]),
            ]);
    }
}
