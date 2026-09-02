<?php

namespace App\Filament\Resources\Alerts\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class AlertForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('severity')
                    ->required(),
                TextInput::make('source')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                Textarea::make('body')
                    ->columnSpanFull(),
                Textarea::make('ai_summary')
                    ->columnSpanFull(),
                Textarea::make('ai_action')
                    ->columnSpanFull(),
                Textarea::make('context')
                    ->columnSpanFull(),
                Select::make('node_id')
                    ->relationship('node', 'name'),
                DateTimePicker::make('acknowledged_at'),
                TextInput::make('acknowledged_by'),
            ]);
    }
}
