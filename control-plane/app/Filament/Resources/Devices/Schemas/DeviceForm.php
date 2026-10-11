<?php

namespace App\Filament\Resources\Devices\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DeviceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('subscription_id')->disabled()->dehydrated(false)
                    ->relationship('subscription', 'id')
                    ->required(),
                TextInput::make('fingerprint')->disabled()->dehydrated(false)
                    ->required(),
                TextInput::make('platform')->disabled()->dehydrated(false),
                TextInput::make('name'),
                DateTimePicker::make('last_seen_at')->disabled()->dehydrated(false),
                DateTimePicker::make('revoked_at')->disabled()->dehydrated(false),
            ]);
    }
}
