<?php

namespace App\Filament\Resources\Nodes\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class NodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('region')
                    ->required(),
                TextInput::make('public_host')
                    ->required(),
                TextInput::make('cdn_host'),
                TextInput::make('api_base')
                    ->required(),
                TextInput::make('api_secret')
                    ->required(),
                TextInput::make('reality_pubkey'),
                TextInput::make('reality_short_id'),
                TextInput::make('reality_sni'),
                TextInput::make('hysteria_port_range'),
                TextInput::make('hysteria_cert_sha256'),
                TextInput::make('capacity')
                    ->required()
                    ->numeric()
                    ->default(500),
                TextInput::make('status')
                    ->required()
                    ->default('provisioning'),
                TextInput::make('peer_version')
                    ->required()
                    ->numeric()
                    ->default(0),
                Textarea::make('health')
                    ->columnSpanFull(),
                DateTimePicker::make('last_health_at'),
            ]);
    }
}
