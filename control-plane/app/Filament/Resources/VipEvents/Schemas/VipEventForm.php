<?php

namespace App\Filament\Resources\VipEvents\Schemas;

use App\Models\PaymentMethod;
use App\Models\VipEvent;
use App\Payments\PaymentManager;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
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
            // One column of full-width sections: side-by-side sections squeezed every field.
            ->columns(1)
            ->components([
                // Edit page: what is happening right now, before any field.
                Section::make('Right now')
                    ->visible(fn (?VipEvent $record) => $record !== null)
                    ->schema([
                        Html::make(fn (?VipEvent $record) => $record ? self::status($record) : ''),
                    ]),

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
                            ->helperText(fn (Get $get) => url('/vip/'.($get('slug') ?: '…')).' — printed QR codes break if you change it.'),
                        Textarea::make('description')
                            ->label('Message on the page (optional)')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('VPN access')
                    ->description('Every pass stops by itself at the end time.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        DateTimePicker::make('ends_at')
                            ->label('Access ends')
                            ->required()
                            ->seconds(false)
                            ->timezone(fn (Get $get) => $get('timezone') ?: 'Asia/Shanghai')
                            ->default(now('Asia/Shanghai')->addDays(3)->setTime(23, 59))
                            ->helperText('Moving it moves every pass.'),
                        Select::make('timezone')
                            ->label('Time zone')
                            ->options(self::TIMEZONES)
                            ->default('Asia/Shanghai')
                            ->required()
                            ->live(),
                        Toggle::make('access_enabled')
                            ->label('VPN on for every pass')
                            ->helperText('Off cuts every guest off within seconds.')
                            ->default(true),
                        Toggle::make('sales_open')
                            ->label('Selling')
                            ->helperText('Off stops new sales; sold passes keep working.')
                            ->default(true),
                    ]),

                Section::make('Price per device')
                    ->description('Normal amounts (25 = ¥25). Stripe/Alipay charges CNY, Pesapal '
                        .strtoupper(PaymentManager::currencyFor('pesapal')).', ClickPesa TZS. Price 0 hides that method.')
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
                    ->description('Tick what guests of this event can pay with. This does not change the normal app.')
                    ->schema([
                        CheckboxList::make('providers')
                            ->label('Offer')
                            ->options(self::paymentChoices())
                            ->descriptions(self::paymentHints($available))
                            ->default(self::defaultPaymentChoices())
                            // Older events saved "manual" (all QR methods) or nothing (everything).
                            ->afterStateHydrated(function (CheckboxList $component, $state, ?VipEvent $record) {
                                if (! $record) {
                                    return;
                                }
                                $state = array_values((array) $state);
                                $all = array_keys(self::paymentChoices());
                                if ($state === []) {
                                    $state = $all;
                                } elseif (in_array('manual', $state, true)) {
                                    $state = array_values(array_unique(array_merge(
                                        array_diff($state, ['manual']),
                                        array_filter($all, fn ($k) => str_starts_with($k, 'manual:')),
                                    )));
                                }
                                $component->state($state);
                            })
                            ->required()
                            ->validationMessages(['required' => 'Tick at least one payment method. To stop selling, switch "Selling" off.']),
                    ]),
            ]);
    }

    private const GATEWAYS = [
        'stripe' => 'Stripe — Alipay + card (CNY)',
        'pesapal' => 'Pesapal — Visa / Mastercard',
        'clickpesa' => 'ClickPesa — M-Pesa, Tigo, Airtel, card (TZS)',
    ];

    /** Each QR method by name (Alipay, WeChat Pay, ...), then the online gateways. */
    private static function paymentChoices(): array
    {
        return PaymentMethod::orderBy('sort')->orderBy('id')->get()
            ->mapWithKeys(fn (PaymentMethod $m) => ['manual:'.$m->id => trim($m->label)])
            ->all() + self::GATEWAYS;
    }

    private static function paymentHints(array $available): array
    {
        return PaymentMethod::get()->mapWithKeys(fn (PaymentMethod $m) => ['manual:'.$m->id => ! $m->is_active
            ? 'Switched off in Setup › Payment Methods — hidden'
            : 'Guest pays and uploads a screenshot; you approve'.($m->qr_path ? '' : ' (no QR image yet)'),
        ])->all() + collect(VipEvent::PROVIDERS)->mapWithKeys(fn ($p) => [
            $p => in_array($p, $available, true) ? 'Ready' : 'Not set up (no keys) — hidden',
        ])->all();
    }

    /** A new event starts with Alipay and WeChat Pay. */
    private static function defaultPaymentChoices(): array
    {
        $manual = PaymentMethod::where('is_active', true)->get();
        $china = $manual->filter(fn (PaymentMethod $m) => in_array($m->type, ['alipay', 'wechat'], true)
            || preg_match('/alipay|wechat/i', (string) $m->label));

        return ($china->isNotEmpty() ? $china : $manual)->map(fn (PaymentMethod $m) => 'manual:'.$m->id)->values()->all();
    }

    /** The "Right now" banner: on/off, sales, passes, and the public link. */
    private static function status(VipEvent $e): HtmlString
    {
        $active = $e->passes()->where('status', 'active');
        $passes = (clone $active)->count();
        $devices = (int) (clone $active)->sum('devices');
        $waiting = $e->passes()->where('status', 'pending')
            ->whereHas('invoice', fn ($q) => $q->where('status', 'pending_review'))->count();
        $vpnOn = $e->access_enabled && ! $e->hasEnded();
        $url = $e->publicUrl();

        $cell = fn (string $k, string $v, string $s = '', string $cls = '') => '<div class="cell"><div class="k">'.e($k).'</div>'
            .'<div class="v '.$cls.'">'.e($v).'</div>'.($s !== '' ? '<div class="s">'.e($s).'</div>' : '').'</div>';

        return new HtmlString('<div class="mvpn-vip-status">'
            .$cell('VPN for guests', $e->hasEnded() ? 'Ended' : ($vpnOn ? 'On' : 'Off'), $e->hasEnded() ? $e->ends_at->diffForHumans() : 'ends '.$e->ends_at->diffForHumans(), $vpnOn ? 'on' : 'off')
            .$cell('QR page', $e->isSelling() ? 'Selling' : 'Closed', $e->priceLabel('cny').' per device', $e->isSelling() ? 'on' : 'off')
            .$cell('Passes', (string) $passes, $devices.' device'.($devices === 1 ? '' : 's'))
            .$cell('To approve', (string) $waiting, $waiting ? 'Alipay screenshots waiting' : 'nothing waiting')
            .'</div>'
            .'<div class="mvpn-vip-link"><span style="flex:1">'.e($url).'</span>'
            .'<a href="'.e($url).'" target="_blank" rel="noopener">Open</a></div>');
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
