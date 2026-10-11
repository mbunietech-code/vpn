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
                Select::make('user_id')->disabled()->dehydrated(false)
                    // OTP users have no name - label them by email/phone instead
                    // (a null title attribute makes Filament throw a 500).
                    ->relationship('user', 'email')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->email ?? $record->phone ?? "#{$record->id}")
                    ->searchable(['email', 'phone'])
                    ->required(),
                TextInput::make('plan_code')->disabled()->dehydrated(false)
                    ->required(),
                TextInput::make('status')->disabled()->dehydrated(false)
                    ->required()
                    ->default('pending'),
                TextInput::make('max_devices')
                    ->required()
                    ->numeric()
                    ->default(2),
                TextInput::make('data_used_mb')->disabled()->dehydrated(false)
                    ->required()
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('started_at')->disabled()->dehydrated(false),
                DateTimePicker::make('expires_at')->disabled()->dehydrated(false),
                DateTimePicker::make('last_synced_at')->disabled()->dehydrated(false),
            ]);
    }
}
