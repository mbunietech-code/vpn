<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name'),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                TextInput::make('phone')
                    ->tel(),
                DateTimePicker::make('email_verified_at')->disabled()->dehydrated(false),
                DateTimePicker::make('phone_verified_at')->disabled()->dehydrated(false),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    // Only change it when something is typed; empty keeps the current one.
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText('Leave empty to keep the current password. Only admins need one.'),
                Toggle::make('is_admin')
                    ->required(),
                TextInput::make('status')->disabled()->dehydrated(false)
                    ->required()
                    ->default('active'),
                TextInput::make('locale')
                    ->required()
                    ->default('sw'),
                TextInput::make('preferred_currency')
                    ->required()
                    ->default('usd'),
                TextInput::make('signup_ip')->disabled()->dehydrated(false),
            ]);
    }
}
