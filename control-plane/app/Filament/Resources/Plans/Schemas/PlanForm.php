<?php

namespace App\Filament\Resources\Plans\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('days')
                    ->required()
                    ->numeric(),
                TextInput::make('max_devices')
                    ->required()
                    ->numeric()
                    ->default(2),
                TextInput::make('data_cap_mb')
                    ->numeric(),
                Textarea::make('node_scope')
                    ->columnSpanFull(),
                TextInput::make('price_usd_cents')
                    ->required()
                    ->numeric(),
                TextInput::make('price_cny_cents')
                    ->required()
                    ->numeric(),
                TextInput::make('sort')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
