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
                ImageColumn::make('qr_path')->visibleFrom('md')->label('QR')->disk('public')->height(44),
                TextColumn::make('label')->searchable(),
                TextColumn::make('type')->visibleFrom('md')->badge(),
                TextColumn::make('currency')->visibleFrom('md')->badge()->placeholder('any'),
                TextColumn::make('account_ref')->visibleFrom('md')->limit(24)->toggleable(),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('sort')->visibleFrom('md')->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
