<?php

namespace App\Filament\Resources\Settings\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('label_display')
                ->label('Setting')
                ->content(fn ($record) => $record?->label ?? '—'),

            Placeholder::make('key_display')
                ->label('Key')
                ->content(fn ($record) => $record?->key ?? '—'),

            TextInput::make('value')
                ->label('Value')
                ->password(fn ($record) => (bool) ($record?->is_secret))
                ->revealable()
                ->autocomplete(false)
                ->helperText('Huhifadhiwa encrypted. Wacha tupu kutumia thamani ya .env.'),
        ]);
    }
}
