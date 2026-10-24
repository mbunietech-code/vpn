<?php

namespace App\Services;

use App\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Paid-invoice totals for the admin dashboard: what was actually taken per
 * currency, plus a rough USD figure (Invoice::usdCents) to compare periods.
 */
class RevenueSummary
{
    /** @return Collection<int, Invoice> */
    public static function paid(CarbonInterface $from, ?CarbonInterface $to = null): Collection
    {
        return Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $from)
            ->when($to, fn ($q) => $q->where('paid_at', '<', $to))
            ->get(['id', 'currency', 'amount_cents', 'paid_at', 'plan_code', 'user_id']);
    }

    /** @param  Collection<int, Invoice>  $invoices */
    public static function usd(Collection $invoices): float
    {
        return $invoices->sum(fn (Invoice $i) => $i->usdCents()) / 100;
    }

    /**
     * "¥630 + TSh 3,900", or '' when nothing was paid.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    public static function perCurrency(Collection $invoices): string
    {
        return $invoices->groupBy('currency')
            ->map(fn ($rows, $cur) => self::money((int) $rows->sum('amount_cents'), $cur))
            ->values()->implode(' + ');
    }

    /** "¥28", "TSh 10,000", "$3.99". */
    public static function money(int $cents, string $currency): string
    {
        $amount = $cents / 100;

        return match ($currency) {
            'usd' => '$'.number_format($amount, 2),
            'cny' => '¥'.number_format($amount, $amount == floor($amount) ? 0 : 2),
            'tzs' => 'TSh '.number_format($amount),
            default => strtoupper($currency).' '.number_format($amount, 2),
        };
    }
}
