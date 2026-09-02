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
                Select::make('subscription_id')
                    ->relationship('subscription', 'id')
                    ->required(),
                TextInput::make('fingerprint')
                    ->required(),
                TextInput::make('platform'),
                TextInput::make('name'),
                DateTimePicker::make('last_seen_at'),
                DateTimePicker::make('revoked_at'),
            ]);
    }
}
