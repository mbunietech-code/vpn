<?php

namespace App\Filament\Resources\PaymentMethods\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PaymentMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->options([
                    'alipay' => 'Alipay',
                    'wechat' => 'WeChat Pay',
                    'bank' => 'Bank transfer',
                    'crypto' => 'Crypto (USDT)',
                    'other' => 'Other',
                ])->required(),
            TextInput::make('label')
                ->helperText('Shown to the customer, e.g. "Alipay (¥)"')
                ->required(),
            Select::make('currency')
                ->options(['cny' => 'CNY (¥)', 'usd' => 'USD ($)'])
                ->placeholder('Any'),
            FileUpload::make('qr_path')
                ->label('QR code image')
                ->image()
                ->directory('qr')
                ->disk('public')
                ->imagePreviewHeight('180'),
            TextInput::make('account_ref')
                ->label('Account / address (text)')
                ->helperText('Account number, wallet address, etc.'),
            Textarea::make('instructions')
                ->rows(3)
                ->helperText('Payment instructions, e.g. "Pay the exact amount, then upload a screenshot."'),
            Toggle::make('is_active')->default(true),
            TextInput::make('sort')->numeric()->default(0),
        ]);
    }
}
