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
                ->helperText('Inavyoonekana kwa mtumiaji, mfano "Alipay (¥)"')
                ->required(),
            Select::make('currency')
                ->options(['cny' => 'CNY (¥)', 'usd' => 'USD ($)'])
                ->placeholder('Yoyote'),
            FileUpload::make('qr_path')
                ->label('QR code image')
                ->image()
                ->directory('qr')
                ->disk('public')
                ->imagePreviewHeight('180'),
            TextInput::make('account_ref')
                ->label('Account / address (maandishi)')
                ->helperText('Nambari ya akaunti, anwani ya wallet, n.k.'),
            Textarea::make('instructions')
                ->rows(3)
                ->helperText('Maelekezo ya kulipa. Mfano: "Lipa kiasi kamili, kisha pakia screenshot."'),
            Toggle::make('is_active')->default(true),
            TextInput::make('sort')->numeric()->default(0),
        ]);
    }
}
