<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyPartnerSignature;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EduHubIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-partner-secret-0123456789abcdef0123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
        config([
            'services.eduhub.url' => 'https://eduhub.test',
            'services.eduhub.partner_secret' => self::SECRET,
        ]);
    }

    // ---- partner API ---------------------------------------------------------

    private function signed(string $method, string $uri, array $body = [], ?int $ts = null, ?string $secret = null)
    {
        $ts ??= time();
        $raw = $body ? json_encode($body) : '';
        $sig = VerifyPartnerSignature::sign($secret ?? self::SECRET, $ts, $method, $uri, $raw);
        $headers = [
            'X-Mvpn-Timestamp' => (string) $ts,
            'X-Mvpn-Signature' => $sig,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        return $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars($headers), $raw);
    }

    private function activation(array $over = []): array
    {
        $plan = Plan::where('code', 'm1')->firstOrFail();
        $plan->update(['price_tzs_cents' => 1000000]);

        return array_merge([
            'reference' => 'CPX-123',
            'eduhub_user_id' => 77,
            'email' => 'buyer@example.com',
            'name' => 'Buyer',
            'email_verified' => true,
            'plan_code' => 'm1',
            'amount_cents' => 1000000,
            'currency' => 'tzs',
        ], $over);
    }

    public function test_partner_api_rejects_bad_signature_and_stale_requests(): void
    {
        $this->signed('GET', '/api/partner/stats', secret: 'wrong-secret-0123456789abcdef0123456789')
            ->assertStatus(401)->assertJson(['error' => 'bad_signature']);
        $this->signed('GET', '/api/partner/stats', ts: time() - 600)
            ->assertStatus(401)->assertJson(['error' => 'stale_or_missing_timestamp']);
        $this->getJson('/api/partner/stats')->assertStatus(401);
    }

    public function test_partner_api_closed_when_secret_missing(): void
    {
        config(['services.eduhub.partner_secret' => null]);
        $this->signed('GET', '/api/partner/stats')->assertStatus(503);
    }

    public function test_activation_provisions_once_and_is_idempotent(): void
    {
        $this->signed('POST', '/api/partner/activations', $this->activation())
            ->assertCreated()->assertJsonPath('duplicate', false)->assertJsonPath('subscription.status', 'active');

        $user = User::where('eduhub_user_id', 77)->firstOrFail();
        $expires = $user->activeSubscription()->expires_at;

        // EduHub retries the same verified payment: no second extension.
        $this->signed('POST', '/api/partner/activations', $this->activation())
            ->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, Invoice::count());
        $this->assertEquals($expires, $user->activeSubscription()->fresh()->expires_at);
    }

    public function test_activation_rejects_short_payment(): void
    {
        $this->signed('POST', '/api/partner/activations', $this->activation(['amount_cents' => 500]))
            ->assertStatus(422)->assertJson(['error' => 'amount_mismatch']);
        $this->assertSame(0, Invoice::count());
        $this->assertFalse(User::where('eduhub_user_id', 77)->exists());
    }

    public function test_renewal_extends_from_current_expiry(): void
    {
        $this->signed('POST', '/api/partner/activations', $this->activation())->assertCreated();
        $user = User::where('eduhub_user_id', 77)->firstOrFail();
        $first = $user->activeSubscription()->expires_at;
        $days = Plan::where('code', 'm1')->value('days');

        $this->signed('POST', '/api/partner/activations', $this->activation(['reference' => 'CPX-124']))->assertCreated();

        $this->assertEquals($first->copy()->addDays($days)->toDateTimeString(), $user->activeSubscription()->fresh()->expires_at->toDateTimeString());
    }

    public function test_stats_and_customer_endpoints(): void
    {
        $this->signed('POST', '/api/partner/activations', $this->activation())->assertCreated();

        $this->signed('GET', '/api/partner/stats')->assertOk()
            ->assertJsonPath('subscriptions.active', 1)
            ->assertJsonPath('payments.revenue_this_month.tzs.cents', 1000000);

        $this->signed('GET', '/api/partner/customers/77')->assertOk()
            ->assertJsonPath('linked', true)
            ->assertJsonPath('subscription.status', 'active');

        $this->signed('GET', '/api/partner/customers/999')->assertOk()->assertJsonPath('linked', false);
    }
}
