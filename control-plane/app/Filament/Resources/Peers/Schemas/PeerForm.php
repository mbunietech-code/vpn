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
                Select::make('subscription_id')->disabled()->dehydrated(false)
                    ->relationship('subscription', 'id')
                    ->required(),
                Select::make('node_id')->disabled()->dehydrated(false)
                    ->relationship('node', 'name')
                    ->required(),
                TextInput::make('protocol')->disabled()->dehydrated(false)
                    ->required(),
                TextInput::make('remote_id')->disabled()->dehydrated(false)
                    ->required(),
                Textarea::make('secret')->hidden()->dehydrated(false)
                    ->columnSpanFull(),
                TextInput::make('status')->disabled()->dehydrated(false)
                    ->required()
                    ->default('active'),
                TextInput::make('bytes_up')->disabled()->dehydrated(false)
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('bytes_down')->disabled()->dehydrated(false)
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
