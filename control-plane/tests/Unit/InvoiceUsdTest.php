<?php

namespace Tests\Unit;

use App\Models\Invoice;
use Tests\TestCase;

class InvoiceUsdTest extends TestCase
{
    public function test_every_currency_is_converted_for_totals(): void
    {
        config(['services.mvpn.usd_rate' => ['usd' => 1, 'cny' => 7, 'tzs' => 2500]]);

        $this->assertEqualsWithDelta(800, (new Invoice(['currency' => 'usd', 'amount_cents' => 800]))->usdCents(), 0.01);
        $this->assertEqualsWithDelta(1000, (new Invoice(['currency' => 'cny', 'amount_cents' => 7000]))->usdCents(), 0.01);
        // TSh 3,900 is about $1.56, not $3,900.
        $this->assertEqualsWithDelta(156, (new Invoice(['currency' => 'tzs', 'amount_cents' => 390000]))->usdCents(), 0.01);
    }
}
