<?php

namespace App\Filament\Resources\Devices\Tables;

use App\Models\Device;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('subscription.user.email')->label('Mtumiaji')->searchable(),
                TextColumn::make('name')->placeholder('—'),
                TextColumn::make('platform')->badge()->placeholder('—'),
                TextColumn::make('last_seen_at')->label('Mwisho')->since()->placeholder('kamwe'),
                TextColumn::make('revoked_at')->label('Hali')
                    ->badge()
                    ->formatStateUsing(fn ($s) => $s ? 'Imezuiwa' : 'Hai')
                    ->color(fn ($s) => $s ? 'danger' : 'success'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Zuia')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Device $r) => $r->revoked_at === null)
                    ->action(function (Device $r) {
                        $r->update(['revoked_at' => now()]);
                        Notification::make()->success()->title('Kifaa kimezuiwa')->send();
                    }),
            ]);
    }
}
