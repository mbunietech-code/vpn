<?php

namespace App\Filament\Resources\Settings\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')->disabled()->dehydrated(false),
            TextInput::make('key')->disabled()->dehydrated(false),
            TextInput::make('value')
                ->label('Value')
                ->password(fn ($record) => (bool) ($record?->is_secret))
                ->revealable()
                ->autocomplete(false)
                ->helperText('Huhifadhiwa encrypted. Wacha tupu kutumia thamani ya .env.'),
        ]);
    }
}
