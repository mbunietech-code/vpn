<?php

namespace App\Filament\Resources\Settings\Schemas;

use App\Models\Setting;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')->disabled()->dehydrated(false),
            TextInput::make('key')->disabled()->dehydrated(false),
            // A secret is never sent to the browser: the field starts empty
            // and blank keeps the saved value (see EditSetting).
            TextInput::make('value')
                ->label(fn (?Setting $record) => $record?->is_secret && self::configured($record) ? 'New value' : 'Value')
                ->password(fn (?Setting $record) => (bool) $record?->is_secret)
                ->revealable(fn (?Setting $record) => (bool) $record?->is_secret)
                ->autocomplete(fn (?Setting $record) => $record?->is_secret ? 'new-password' : 'off')
                ->helperText(fn (?Setting $record) => $record?->is_secret
                    ? (self::configured($record)
                        ? 'A value is saved (encrypted, not shown). Type a new one to replace it, or leave empty to keep it.'
                        : 'Stored encrypted. Leave empty to use the .env value.')
                    : 'Stored encrypted. Leave empty to use the .env value.'),
            Toggle::make('clear')
                ->label('Remove the saved value (use the .env value instead)')
                ->visible(fn (?Setting $record) => $record?->is_secret && self::configured($record)),
        ]);
    }

    public static function configured(?Setting $record): bool
    {
        return filled($record?->getRawOriginal('value'));
    }
}
