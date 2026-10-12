<x-mail::message>
# Payment to review

**{{ $customer }}** sent a payment screenshot.

<x-mail::panel>
**{{ $amount }}** · plan {{ strtoupper($invoice->plan_code) }} · {{ ucfirst($invoice->payment_method ?? $invoice->provider) }}
@if (! empty($invoice->meta['user_note']))
<br>Note: “{{ $invoice->meta['user_note'] }}”
@endif
</x-mail::panel>

Check the screenshot, then approve to switch their VPN on at once.

<x-mail::button :url="$reviewUrl">Review in EduHub</x-mail::button>

Mbunie VPN
</x-mail::message>
