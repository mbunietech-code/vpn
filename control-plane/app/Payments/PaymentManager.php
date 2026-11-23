<?php

namespace App\Payments;

use App\Payments\Drivers\ClickPesaGateway;
use App\Payments\Drivers\CryptomusGateway;
use App\Payments\Drivers\PesapalGateway;
use App\Payments\Drivers\StripeGateway;
use InvalidArgumentException;

class PaymentManager
{
    /** @var array<string, class-string<PaymentGateway>> */
    protected array $drivers = [
        'clickpesa' => ClickPesaGateway::class,
        'stripe' => StripeGateway::class,
        'pesapal' => PesapalGateway::class,
        'cryptomus' => CryptomusGateway::class,
    ];

    /** provider => currency it charges in (null = the buyer picks usd/cny) */
    public const CURRENCY = [
        'clickpesa' => 'tzs',
        'stripe' => null,     // usd or cny
        'pesapal' => 'usd',   // overridden by services.pesapal.currency
        'cryptomus' => null,  // usd or cny
    ];

    /** What the buyer sees on the payment button. */
    public const LABELS = [
        'clickpesa' => 'M-Pesa · Tigo · Airtel · Bank (TZS)',
        'stripe' => 'Alipay · Card',
        'pesapal' => 'Visa · Mastercard (Pesapal)',
        'cryptomus' => 'Crypto (USDT)',
    ];

    public function driver(string $name): PaymentGateway
    {
        if (! isset($this->drivers[$name])) {
            throw new InvalidArgumentException("Unknown payment provider [$name]");
        }

        return app($this->drivers[$name]);
    }

    /** Providers that have credentials configured. */
    public function available(): array
    {
        return array_values(array_filter(
            array_keys($this->drivers),
            fn (string $name) => $this->driver($name)->key() !== ''
        ));
    }

    /**
     * The currency a provider is charged in when nobody picks one.
     * Stripe: CNY, so Alipay is offered (it is card-only in other currencies).
     */
    public static function currencyFor(string $provider): string
    {
        if ($provider === 'pesapal') {
            return strtolower((string) config('services.pesapal.currency', 'usd')) ?: 'usd';
        }

        return self::CURRENCY[$provider] ?? 'cny';
    }
}
