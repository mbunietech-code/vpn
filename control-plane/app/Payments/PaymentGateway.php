<?php

namespace App\Payments;

use App\Models\Invoice;
use Illuminate\Http\Request;

/**
 * A payment provider driver. Adding Flutterwave / Selcom / PayPal later
 * means implementing this interface once - no core changes.
 * See 05-Addendum-MVPN.md §A3.
 */
interface PaymentGateway
{
    public function key(): string;

    /**
     * Create a hosted checkout for the invoice and return the URL the app
     * should open. The provider handles all card / Alipay / WeChat / crypto
     * entry on its own page - MVPN never touches raw payment credentials.
     *
     * @return array{pay_url: string, provider_ref: string}
     */
    public function createCheckout(Invoice $invoice): array;

    /**
     * Verify the webhook signature. Returns true only for authentic,
     * non-tampered requests.
     */
    public function verifySignature(Request $request): bool;

    /**
     * Parse a verified webhook into a normalized event.
     *
     * @return array{
     *   event_id: string|null,
     *   type: 'paid'|'failed'|'refunded'|'ignored',
     *   provider_ref: string|null,
     *   amount_cents: int|null,
     *   currency: string|null,
     *   invoice_id: int|null
     * }
     */
    public function parseEvent(Request $request): array;
}
