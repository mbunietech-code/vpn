<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('email')->searchable()->placeholder('—'),
                TextColumn::make('phone')->searchable()->placeholder('—'),
                IconColumn::make('is_admin')->label('Admin')->boolean(),
                TextColumn::make('status')->badge()
                    ->color(fn ($s) => $s === 'active' ? 'success' : 'danger'),
                TextColumn::make('subscriptions_count')->label('Subs')->counts('subscriptions'),
                TextColumn::make('created_at')->since()->label('Alijiunga'),
            ])
            ->recordActions([EditAction::make()]);
    }
}
