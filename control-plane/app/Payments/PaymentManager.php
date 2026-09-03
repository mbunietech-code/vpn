<?php

namespace App\Payments;

use App\Payments\Drivers\ClickPesaGateway;
use App\Payments\Drivers\CryptomusGateway;
use App\Payments\Drivers\StripeGateway;
use InvalidArgumentException;

class PaymentManager
{
    /** @var array<string, class-string<PaymentGateway>> */
    protected array $drivers = [
        'clickpesa' => ClickPesaGateway::class,
        'stripe' => StripeGateway::class,
        'cryptomus' => CryptomusGateway::class,
    ];

    /** provider => currency it charges in */
    public const CURRENCY = [
        'clickpesa' => 'tzs',
        'stripe' => null,     // usd or cny
        'cryptomus' => null,  // usd or cny
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
}
