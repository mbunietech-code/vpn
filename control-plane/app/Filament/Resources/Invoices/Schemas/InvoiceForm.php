<?php

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')->disabled()->dehydrated(false)
                    // OTP users have no name - label them by email/phone instead
                    // (a null title attribute makes Filament throw a 500).
                    ->relationship('user', 'email')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->email ?? $record->phone ?? "#{$record->id}")
                    ->searchable(['email', 'phone'])
                    ->required(),
                TextInput::make('plan_code')->disabled()->dehydrated(false)
                    ->required(),
                TextInput::make('provider')->disabled()->dehydrated(false)
                    ->required(),
                TextInput::make('currency')->disabled()->dehydrated(false)
                    ->required(),
                TextInput::make('amount_cents')->disabled()->dehydrated(false)
                    ->required()
                    ->numeric(),
                TextInput::make('status')->disabled()->dehydrated(false)
                    ->required()
                    ->default('pending'),
                TextInput::make('provider_ref')->disabled()->dehydrated(false),
                TextInput::make('idempotency_key')->disabled()->dehydrated(false)
                    ->required(),
                Textarea::make('meta')->disabled()->dehydrated(false)
                    ->columnSpanFull(),
                DateTimePicker::make('paid_at')->disabled()->dehydrated(false),
                DateTimePicker::make('expires_at')->disabled()->dehydrated(false),
            ]);
    }
}
