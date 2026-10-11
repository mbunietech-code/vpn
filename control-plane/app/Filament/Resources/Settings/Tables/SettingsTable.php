<?php

namespace App\Filament\Resources\Settings\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('group')->visibleFrom('md')->badge()->sortable(),
                TextColumn::make('label')->searchable()->wrap(),
                TextColumn::make('key')->visibleFrom('md')->color('gray'),
                IconColumn::make('configured')
                    ->label('Set')
                    ->boolean()
                    ->getStateUsing(fn ($record) => filled($record->getRawOriginal('value'))),
                TextColumn::make('updated_at')->visibleFrom('md')->since()->label('Updated')->toggleable(),
            ])
            ->defaultSort('sort')
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
