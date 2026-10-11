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
                TextInput::make('severity')->disabled()->dehydrated(false)
                    ->required(),
                TextInput::make('source')->disabled()->dehydrated(false)
                    ->required(),
                TextInput::make('title')->disabled()->dehydrated(false)
                    ->required(),
                Textarea::make('body')->disabled()->dehydrated(false)
                    ->columnSpanFull(),
                Textarea::make('ai_summary')->disabled()->dehydrated(false)
                    ->columnSpanFull(),
                Textarea::make('ai_action')->disabled()->dehydrated(false)
                    ->columnSpanFull(),
                Textarea::make('context')->disabled()->dehydrated(false)
                    ->columnSpanFull(),
                Select::make('node_id')->disabled()->dehydrated(false)
                    ->relationship('node', 'name'),
                DateTimePicker::make('acknowledged_at')->disabled()->dehydrated(false),
                TextInput::make('acknowledged_by')->disabled()->dehydrated(false),
            ]);
    }
}
