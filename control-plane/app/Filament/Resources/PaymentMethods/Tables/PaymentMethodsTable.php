<?php

namespace App\Filament\Resources\PaymentMethods\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentMethodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                ImageColumn::make('qr_path')->label('QR')->disk('public')->height(44),
                TextColumn::make('label')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('currency')->badge()->placeholder('any'),
                TextColumn::make('account_ref')->limit(24)->toggleable(),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('sort')->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
