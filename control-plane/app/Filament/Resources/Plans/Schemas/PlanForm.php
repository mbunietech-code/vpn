<?php

namespace App\Filament\Resources\Plans\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Plan')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->placeholder('1 Month'),
                        TextInput::make('code')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->regex('/^[a-z0-9_-]+$/')
                            ->placeholder('m1')
                            ->helperText('Short code used by the apps and by MbunieHub plans. Do not change it once customers have bought it.'),
                        TextInput::make('days')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->suffix('days'),
                        TextInput::make('max_devices')
                            ->label('Devices')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->default(2),
                        TextInput::make('data_cap_mb')
                            ->label('Data cap')
                            ->integer()
                            ->minValue(0)
                            ->suffix('MB')
                            ->helperText('Leave empty for unlimited.'),
                        TextInput::make('sort')
                            ->label('Order')
                            ->required()
                            ->integer()
                            ->default(0),
                        Toggle::make('is_active')
                            ->label('Available for sale')
                            ->default(true),
                    ]),

                // Prices are typed in normal amounts ($8, ¥58, TSh 20,000) and stored in cents.
                Section::make('Prices')
                    ->description('Type the normal amount, e.g. 8 for $8. A price of 0 means the plan is not sold in that currency.')
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        self::price('price_usd_cents', 'USD', '$', 2),
                        self::price('price_cny_cents', 'CNY', '¥', 2),
                        self::price('price_tzs_cents', 'TZS', 'TSh', 0)
                            ->helperText('Needed to sell this plan through MbunieHub.'),
                    ]),

                Section::make('Advanced')
                    ->collapsed()
                    ->schema([
                        Textarea::make('node_scope')
                            ->label('Node scope')
                            ->helperText('Optional. Leave empty to use every online node.'),
                    ]),
            ]);
    }

    private static function price(string $field, string $label, string $prefix, int $decimals): TextInput
    {
        return TextInput::make($field)
            ->label($label)
            ->prefix($prefix)
            ->numeric()
            ->minValue(0)
            ->step($decimals ? 0.01 : 1)
            ->default(0)
            ->required()
            ->formatStateUsing(fn ($state) => $state === null ? 0 : round(((int) $state) / 100, $decimals))
            ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100));
    }
}
