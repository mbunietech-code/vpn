<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Node;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\MvpnSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End to end with Stripe faked: app checkout -> Stripe Checkout Session ->
 * signed webhook -> invoice paid -> subscription + VPN peers active.
 */
class StripePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MvpnSeeder::class);
        config([
            'services.stripe.key' => 'pk_test_x',
            'services.stripe.secret' => 'sk_test_x',
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        Node::create([
            'name' => 'KL1', 'region' => 'my', 'public_host' => 'n1.example.com',
            'api_secret' => Str::random(40), 'status' => 'online',
            'reality_pubkey' => 'PK', 'reality_short_id' => 'ab',
        ]);
        Plan::where('code', 'm1')->update(['price_cny_cents' => 5500, 'price_usd_cents' => 800]);
    }

    private function checkout(User $user, string $currency = 'cny'): Invoice
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
        ])]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', ['plan' => 'm1', 'provider' => 'stripe', 'currency' => $currency])
            ->assertOk()
            ->assertJsonPath('pay_url', 'https://checkout.stripe.com/c/pay/cs_test_123');

        return Invoice::latest('id')->firstOrFail();
    }

    private function webhook(array $event, ?string $secret = null)
    {
        $body = json_encode($event);
        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$body, $secret ?? self::WEBHOOK_SECRET);

        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$sig}",
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function sessionEvent(Invoice $invoice, string $type = 'checkout.session.completed', string $paymentStatus = 'paid', ?int $amount = null): array
    {
        return [
            'id' => 'evt_'.Str::random(10),
            'type' => $type,
            'data' => ['object' => [
                'id' => 'cs_test_123',
                'payment_status' => $paymentStatus,
                'amount_total' => $amount ?? $invoice->amount_cents,
                'currency' => $invoice->currency,
                'client_reference_id' => (string) $invoice->id,
                'metadata' => ['invoice_id' => (string) $invoice->id],
            ]],
        ];
    }

    public function test_wechat_pay_only_when_switched_on_in_settings(): void
    {
        config(['services.stripe.methods' => 'alipay, wechat_pay ,card']);
        $this->checkout(User::factory()->create());

        Http::assertSent(fn ($r) => ($r->data()['payment_method_types[1]'] ?? null) === 'wechat_pay'
            && ($r->data()['payment_method_options[wechat_pay][client]'] ?? null) === 'web');
    }

    public function test_usd_checkout_is_card_only(): void
    {
        $this->checkout(User::factory()->create(), 'usd');

        Http::assertSent(fn ($r) => ($r->data()['payment_method_types[0]'] ?? null) === 'card'
            && ! isset($r->data()['payment_method_types[1]']));
    }

    public function test_checkout_offers_alipay_and_card_for_cny_by_default(): void
    {
        $this->checkout(User::factory()->create());

        Http::assertSent(fn ($r) => str_contains($r->url(), 'checkout/sessions')
            && ($r->data()['payment_method_types[0]'] ?? null) === 'alipay'
            && ($r->data()['payment_method_types[1]'] ?? null) === 'card'
            && ! isset($r->data()['payment_method_types[2]'])
            && ($r->data()['line_items[0][price_data][unit_amount]'] ?? null) == 5500
            && ($r->data()['line_items[0][price_data][currency]'] ?? null) === 'cny');
    }

    public function test_paid_webhook_activates_vpn_once(): void
    {
        $user = User::factory()->create();
        $invoice = $this->checkout($user);
        $event = $this->sessionEvent($invoice);

        $this->webhook($event)->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
        $sub = $user->activeSubscription();
        $this->assertNotNull($sub);
        $this->assertSame(3, $sub->peers()->where('status', 'active')->count());
        $expires = $sub->expires_at;

        // Stripe retries the same event: no second extension.
        $this->webhook($event)->assertOk();
        $this->assertEquals($expires, $user->activeSubscription()->fresh()->expires_at);
    }

    public function test_completed_but_unpaid_waits_for_async_success(): void
    {
        $user = User::factory()->create();
        $invoice = $this->checkout($user);

        $this->webhook($this->sessionEvent($invoice, paymentStatus: 'unpaid'))->assertOk();
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertNull($user->activeSubscription());

        $this->webhook($this->sessionEvent($invoice, 'checkout.session.async_payment_succeeded'))->assertOk();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($user->activeSubscription());
    }

    public function test_forged_or_short_payments_do_not_activate(): void
    {
        $user = User::factory()->create();
        $invoice = $this->checkout($user);

        $this->webhook($this->sessionEvent($invoice), secret: 'whsec_wrong')->assertStatus(400);
        $this->assertSame('pending', $invoice->fresh()->status);

        $this->webhook($this->sessionEvent($invoice, amount: 100))->assertOk();
        $this->assertSame('pending', $invoice->fresh()->status, 'paid less than the price');
        $this->assertNull($user->activeSubscription());
    }

    public function test_return_page_tells_paid_from_cancelled(): void
    {
        $this->get('/pay/return?status=ok')->assertOk()->assertSee('Payment received');
        $this->get('/pay/return?status=cancel')->assertOk()->assertSee('Payment cancelled');
    }

    public function test_stripe_is_offered_to_the_app_once_the_secret_is_set(): void
    {
        $this->getJson('/api/payment-methods')->assertOk()->assertJsonFragment(['provider' => 'stripe']);

        config(['services.stripe.secret' => '']);
        $this->getJson('/api/payment-methods')->assertOk()->assertJsonMissing(['provider' => 'stripe']);
    }
}
