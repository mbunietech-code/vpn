<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SubscriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    // OTP users have no name - label them by email/phone instead
                    // (a null title attribute makes Filament throw a 500).
                    ->relationship('user', 'email')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->email ?? $record->phone ?? "#{$record->id}")
                    ->searchable(['email', 'phone'])
                    ->required(),
                TextInput::make('plan_code')
                    ->required(),
                TextInput::make('status')
                    ->required()
                    ->default('pending'),
                TextInput::make('max_devices')
                    ->required()
                    ->numeric()
                    ->default(2),
                TextInput::make('data_used_mb')
                    ->required()
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('started_at'),
                DateTimePicker::make('expires_at'),
                DateTimePicker::make('last_synced_at'),
            ]);
    }
}
