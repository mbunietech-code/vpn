<?php

namespace App\Payments;

use App\Payments\Drivers\CryptomusGateway;
use App\Payments\Drivers\StripeGateway;
use InvalidArgumentException;

class PaymentManager
{
    /** @var array<string, class-string<PaymentGateway>> */
    protected array $drivers = [
        'stripe' => StripeGateway::class,
        'cryptomus' => CryptomusGateway::class,
    ];

    public function driver(string $name): PaymentGateway
    {
        if (! isset($this->drivers[$name])) {
            throw new InvalidArgumentException("Unknown payment provider [$name]");
        }

        return app($this->drivers[$name]);
    }

    /** @return list<string> */
    public function available(): array
    {
        return array_values(array_filter(
            array_keys($this->drivers),
            fn (string $name) => $this->driver($name)->key() !== '' || $name === 'stripe'
        ));
    }
}
