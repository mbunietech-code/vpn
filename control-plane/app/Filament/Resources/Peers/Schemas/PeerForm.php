<?php

namespace App\Filament\Resources\Peers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class PeerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('subscription_id')
                    ->relationship('subscription', 'id')
                    ->required(),
                Select::make('node_id')
                    ->relationship('node', 'name')
                    ->required(),
                TextInput::make('protocol')
                    ->required(),
                TextInput::make('remote_id')
                    ->required(),
                Textarea::make('secret')
                    ->columnSpanFull(),
                TextInput::make('status')
                    ->required()
                    ->default('active'),
                TextInput::make('bytes_up')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('bytes_down')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
