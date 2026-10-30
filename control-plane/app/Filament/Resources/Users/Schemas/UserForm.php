<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Device;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 2])
            ->components([
                Section::make('Customer')
                    ->description('How they sign in to the app: a one-time code to this email or phone.')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('name')->maxLength(120)->columnSpanFull(),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->maxLength(120)
                            ->unique(ignoreRecord: true)
                            ->requiredWithout('phone')
                            ->validationMessages(['required_without' => 'Give an email or a phone number.']),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(30)
                            ->unique(ignoreRecord: true)
                            ->placeholder('+255…'),
                        Select::make('preferred_currency')
                            ->label('Currency in the app')
                            ->options(['usd' => 'USD ($)', 'cny' => 'CNY (¥)', 'tzs' => 'TZS (TSh)'])
                            ->required()
                            ->default('usd')
                            ->selectablePlaceholder(false),
                        TextEntry::make('status')
                            ->label('Status')
                            ->visibleOn('edit')
                            ->badge()
                            ->color(fn ($state) => $state === 'active' ? 'success' : 'danger')
                            ->formatStateUsing(fn ($state) => ucfirst((string) $state))
                            ->helperText('Change it with Suspend / Restore at the top.'),
                    ]),

                Section::make('VPN')
                    ->description('Plan and devices. Manage them on the Subscriptions and Devices pages.')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('vpn_plan')
                            ->label('Plan')
                            ->state(function (?User $record) {
                                $sub = $record?->subscriptions()->latest('expires_at')->first();
                                if (! $sub) {
                                    return 'No plan';
                                }
                                $live = $sub->status === 'active' && $sub->expires_at?->isFuture();

                                return strtoupper($sub->plan_code).' · '.($live ? 'active' : ($sub->status === 'active' ? 'expired' : $sub->status));
                            }),
                        TextEntry::make('vpn_until')
                            ->label('Until')
                            ->state(function (?User $record) {
                                $sub = $record?->subscriptions()->latest('expires_at')->first();

                                return $sub?->isUnlimited() ? 'No limit (admin)' : ($sub?->expires_at?->format('d M Y H:i') ?? '—');
                            }),
                        TextEntry::make('vpn_devices')
                            ->label('Devices')
                            ->state(function (?User $record) {
                                $ids = $record?->subscriptions()->pluck('id') ?? collect();
                                $all = Device::whereIn('subscription_id', $ids)->whereNull('revoked_at');

                                return (clone $all)->count().' signed in · '.(clone $all)->online()->count().' online now';
                            }),
                        TextEntry::make('eduhub_user_id')
                            ->label('MbunieHub account')
                            ->formatStateUsing(fn ($state) => $state ? '#'.$state : 'not linked')
                            ->placeholder('not linked'),
                    ]),

                Section::make('Admin access')
                    ->description('Only for people who run Mbunie VPN. Customers never need this. Admins also get the VPN with no time limit when they sign in to the app with this email.')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        Toggle::make('is_admin')
                            ->label('Can open this admin panel')
                            ->live()
                            // Nobody can take their own admin access away by mistake.
                            ->disabled(fn (?User $record) => $record !== null && $record->id === auth()->id())
                            ->dehydrated(fn (?User $record) => ! ($record !== null && $record->id === auth()->id()))
                            ->columnSpanFull(),
                        TextInput::make('password')
                            ->label('Admin password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->minLength(10)
                            ->visible(fn (Get $get) => (bool) $get('is_admin'))
                            // Only change it when something is typed; empty keeps the current one.
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText('Leave empty to keep the current password.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Details')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->visibleOn('edit')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('created_at')->label('Joined')->dateTime('d M Y H:i'),
                        TextEntry::make('email_verified_at')->label('Email confirmed')->dateTime('d M Y H:i')->placeholder('not yet'),
                        TextEntry::make('phone_verified_at')->label('Phone confirmed')->dateTime('d M Y H:i')->placeholder('not yet'),
                        TextEntry::make('signup_ip')->label('Signed up from IP')->placeholder('—'),
                    ]),
            ]);
    }
}
