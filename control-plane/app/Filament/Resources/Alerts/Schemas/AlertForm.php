<?php

namespace App\Filament\Resources\Alerts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Read-only details of one alert (the Details pop-up and its page). */
class AlertForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What happened')
                    ->columns(['default' => 1, 'md' => 3])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('title')->disabled()->dehydrated(false)->columnSpanFull(),
                        TextInput::make('severity')->disabled()->dehydrated(false),
                        TextInput::make('source')->disabled()->dehydrated(false),
                        Select::make('node_id')->label('Node')->disabled()->dehydrated(false)
                            ->relationship('node', 'name')->placeholder('—'),
                        Textarea::make('body')->label('Details')->disabled()->dehydrated(false)
                            ->rows(3)->columnSpanFull(),
                    ]),
                Section::make('AI explanation')
                    ->description('Only when an Anthropic API key is set in Settings & Keys → Alerts.')
                    ->collapsible()
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('ai_summary')->label('Summary')->disabled()->dehydrated(false)->rows(2),
                        Textarea::make('ai_action')->label('What to do')->disabled()->dehydrated(false)->rows(2),
                    ]),
                Section::make('Technical data')
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('context')->label('Context')->disabled()->dehydrated(false)->rows(4)
                            ->formatStateUsing(fn ($state) => $state ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null),
                        TextInput::make('acknowledged_at')->label('Resolved at')->disabled()->dehydrated(false)->placeholder('Still open'),
                        TextInput::make('acknowledged_by')->label('Resolved by')->disabled()->dehydrated(false)->placeholder('—'),
                    ]),
            ]);
    }
}
