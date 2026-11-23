<?php

namespace App\Filament\Resources\VipEvents\Schemas;

use App\Models\VipEvent;
use App\Payments\PaymentManager;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class VipEventForm
{
    public const TIMEZONES = [
        'Asia/Shanghai' => 'China (Beijing time)',
        'Africa/Dar_es_Salaam' => 'Tanzania (EAT)',
        'Asia/Hong_Kong' => 'Hong Kong',
        'Asia/Kuala_Lumpur' => 'Malaysia',
        'UTC' => 'UTC',
    ];

    public static function configure(Schema $schema): Schema
    {
        $available = app(PaymentManager::class)->available();

        return $schema
            ->components([
                Section::make('Event')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(120)
                            ->placeholder('China–Africa Forum 2026')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state, ?VipEvent $record) {
                                if (! $record && blank($get('slug'))) {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')
                            ->label('Link name')
                            ->required()
                            ->maxLength(60)
                            ->unique(ignoreRecord: true)
                            ->regex('/^[a-z0-9-]+$/')
                            ->prefix(url('/vip').'/')
                            ->helperText('The QR code opens this link. Changing it later breaks QR codes already printed.'),
                        Textarea::make('description')
                            ->label('Message on the page (optional)')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('VPN access')
                    ->description('Every pass of this event works until the end time, then stops by itself.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        DateTimePicker::make('ends_at')
                            ->label('Access ends')
                            ->required()
                            ->seconds(false)
                            ->timezone(fn (Get $get) => $get('timezone') ?: 'Asia/Shanghai')
                            ->default(now('Asia/Shanghai')->addDays(3)->setTime(23, 59))
                            ->helperText('In the time zone chosen next to it. Moving it later moves every pass.'),
                        Select::make('timezone')
                            ->label('Time zone')
                            ->options(self::TIMEZONES)
                            ->default('Asia/Shanghai')
                            ->required()
                            ->live(),
                        Toggle::make('access_enabled')
                            ->label('VPN on for every pass')
                            ->helperText('Off = every guest of this event is cut off within seconds. On again = they are back.')
                            ->default(true),
                        Toggle::make('sales_open')
                            ->label('Selling')
                            ->helperText('Off = the QR page stops selling. Passes already sold keep working.')
                            ->default(true),
                    ]),

                Section::make('Price per device')
                    ->description('Type normal amounts (25 for ¥25). Each payment method charges in its own currency: Stripe/Alipay in CNY, Pesapal in '
                        .strtoupper(PaymentManager::currencyFor('pesapal')).', ClickPesa in TZS. A method whose currency has price 0 is not offered.')
                    ->columns(['default' => 1, 'md' => 4])
                    ->schema([
                        self::price('price_cny_cents', 'CNY', '¥', 2)->default(2500),
                        self::price('price_usd_cents', 'USD', '$', 2)->default(350),
                        self::price('price_tzs_cents', 'TZS', 'TSh', 0)->default(900000),
                        TextInput::make('max_devices_per_order')
                            ->label('Max devices per purchase')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(50)
                            ->default(5)
                            ->required(),
                    ]),

                Section::make('Payment methods')
                    ->schema([
                        CheckboxList::make('providers')
                            ->label('Offer')
                            ->options([
                                'stripe' => 'Stripe — Alipay + card (CNY)',
                                'pesapal' => 'Pesapal — Visa / Mastercard',
                                'clickpesa' => 'ClickPesa — M-Pesa, Tigo, Airtel, card (TZS)',
                                'manual' => 'Manual QR (Admin › Payment methods, e.g. Alipay) — the guest uploads a screenshot, you approve it',
                            ])
                            ->descriptions(collect(VipEvent::PROVIDERS)->mapWithKeys(fn ($p) => [
                                $p => in_array($p, $available, true) ? 'Keys set — ready' : 'No keys in .env yet — hidden until they are added',
                            ])->all())
                            ->helperText('Leave all unticked to offer every method that is set up.'),
                    ]),
            ]);
    }

    private static function price(string $field, string $label, string $prefix, int $decimals): TextInput
    {
        return TextInput::make($field)
            ->label($label)
            ->prefix($prefix)
            ->numeric()
            ->minValue(0)
            ->step($decimals ? 0.01 : 1)
            ->required()
            ->formatStateUsing(fn ($state) => $state === null ? 0 : round(((int) $state) / 100, $decimals))
            ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100));
    }
}
