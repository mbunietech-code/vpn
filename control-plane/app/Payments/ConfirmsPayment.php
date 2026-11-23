<?php

namespace App\Payments;

use App\Models\Invoice;

/**
 * A provider we can ask directly "is this invoice paid?". Used while a buyer
 * waits on the VIP pass page, so a late or lost webhook doesn't keep them
 * waiting.
 */
interface ConfirmsPayment
{
    /**
     * Same normalized shape as PaymentGateway::parseEvent(), or null when the
     * provider can't say yet.
     *
     * @return array{event_id: string|null, type: string, provider_ref: string|null, amount_cents: int|null, currency: string|null, invoice_id: int|null}|null
     */
    public function confirm(Invoice $invoice): ?array;
}
