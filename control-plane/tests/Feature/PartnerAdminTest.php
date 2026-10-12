<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyPartnerSignature;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Plan;
use App\Models\User;
use App\Services\SubscriptionAdmin;
use Database\Seeders\MvpnSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerAdminTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-partner-secret-0123456789abcdef0123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MvpnSeeder::class);
        config(['services.eduhub.partner_secret' => self::SECRET]);
        Node::create([
            'name' => 'KL1', 'region' => 'my', 'public_host' => 'n1.example.com',
            'api_secret' => Str::random(40), 'status' => 'online',
            'reality_pubkey' => 'PK', 'reality_short_id' => 'ab',
        ]);
    }

    private function signed(string $method, string $uri, array $body = [])
    {
        $ts = time();
        $raw = $body ? json_encode($body) : '';
        $headers = [
            'X-Mvpn-Timestamp' => (string) $ts,
            'X-Mvpn-Signature' => VerifyPartnerSignature::sign(self::SECRET, $ts, $method, $uri, $raw),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        return $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars($headers), $raw);
    }

    private function customerWithPlan(): User
    {
        $u = User::factory()->create(['email' => 'cust@example.com']);
        app(SubscriptionAdmin::class)->grant($u, Plan::where('code', 'm1')->first(), 'test');

        return $u;
    }

    public function test_requires_a_valid_signature(): void
    {
        $this->getJson('/api/partner/admin/customers')->assertStatus(401);
    }

    public function test_customer_list_search_and_detail(): void
    {
        $u = $this->customerWithPlan();
        User::factory()->create(['email' => 'other@example.com']);

        $this->signed('GET', '/api/partner/admin/customers?q=cust')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'cust@example.com')
            ->assertJsonPath('data.0.subscription.status', 'active');

        $this->signed('GET', "/api/partner/admin/customers/{$u->id}")
            ->assertOk()
            ->assertJsonPath('customer.id', $u->id)
            ->assertJsonCount(1, 'subscriptions');
    }

    public function test_extend_suspend_restore_are_audited_with_the_eduhub_admin(): void
    {
        $sub = $this->customerWithPlan()->activeSubscription();
        $before = $sub->expires_at;

        $this->signed('POST', "/api/partner/admin/subscriptions/{$sub->id}/extend", ['days' => 7, 'actor' => 'boss@mbuniehub.com'])->assertOk();
        $this->assertEquals($before->addDays(7)->timestamp, $sub->fresh()->expires_at->timestamp);

        $this->signed('POST', "/api/partner/admin/subscriptions/{$sub->id}/suspend", ['actor' => 'boss@mbuniehub.com'])
            ->assertOk()->assertJsonPath('subscription.status', 'suspended');
        $this->signed('POST', "/api/partner/admin/subscriptions/{$sub->id}/restore", ['actor' => 'boss@mbuniehub.com'])
            ->assertOk()->assertJsonPath('subscription.status', 'active');

        $this->assertTrue(AuditLog::where('actor', 'eduhub:boss@mbuniehub.com')->where('action', 'subscription.extended')->exists());
    }

    public function test_grant_and_customer_suspension(): void
    {
        $u = User::factory()->create();

        $this->signed('POST', "/api/partner/admin/customers/{$u->id}/grant", ['plan_code' => 'm1', 'actor' => 'a@b.c'])
            ->assertOk()->assertJsonPath('subscription.status', 'active');

        $this->signed('POST', "/api/partner/admin/customers/{$u->id}/status", ['status' => 'suspended', 'actor' => 'a@b.c'])
            ->assertOk()->assertJsonPath('customer.status', 'suspended');
        $this->assertSame('suspended', $u->subscriptions()->first()->status);
    }

    public function test_device_remove_and_allow(): void
    {
        $sub = $this->customerWithPlan()->activeSubscription();
        $d = $sub->devices()->create(['fingerprint' => 'x', 'platform' => 'android', 'model' => 'OPPO']);

        $this->signed('GET', '/api/partner/admin/devices?q=OPPO')->assertOk()->assertJsonPath('data.0.id', $d->id);
        $this->signed('POST', "/api/partner/admin/devices/{$d->id}/remove", ['actor' => 'a@b.c'])
            ->assertOk()->assertJsonPath('device.state', 'removed');
        $this->signed('POST', "/api/partner/admin/devices/{$d->id}/allow", ['actor' => 'a@b.c'])
            ->assertOk()->assertJsonPath('device.revoked_at', null);
    }

    public function test_approve_and_reject_manual_payments(): void
    {
        $u = User::factory()->create();
        $mk = fn () => Invoice::create([
            'user_id' => $u->id, 'plan_code' => 'm1', 'provider' => 'manual', 'payment_method' => 'alipay',
            'currency' => 'cny', 'amount_cents' => 5500, 'status' => 'pending_review',
            'idempotency_key' => Str::uuid(), 'proof_path' => 'proofs/x.jpg',
        ]);
        $a = $mk();
        $b = $mk();

        $this->signed('GET', '/api/partner/admin/invoices?status=review')->assertOk()->assertJsonCount(2, 'data');

        $this->signed('POST', "/api/partner/admin/invoices/{$a->id}/approve", ['actor' => 'a@b.c'])
            ->assertOk()->assertJsonPath('invoice.status', 'paid');
        $this->assertNotNull($u->activeSubscription());

        $this->signed('POST', "/api/partner/admin/invoices/{$b->id}/reject", ['actor' => 'a@b.c'])->assertStatus(422); // reason required
        $this->signed('POST', "/api/partner/admin/invoices/{$b->id}/reject", ['reason' => 'blurry', 'actor' => 'a@b.c'])
            ->assertOk()->assertJsonPath('invoice.status', 'failed');
    }

    public function test_plan_prices_are_edited_in_normal_units(): void
    {
        $plan = Plan::where('code', 'm1')->first();

        $this->signed('PUT', "/api/partner/admin/plans/{$plan->id}", [
            'name' => 'Monthly', 'days' => 30, 'max_devices' => 3,
            'price_usd' => 8, 'price_cny' => 55, 'price_tzs' => 20000, 'is_active' => true, 'actor' => 'a@b.c',
        ])->assertOk()->assertJsonPath('plan.price_cny', 55);

        $plan->refresh();
        $this->assertSame(800, $plan->price_usd_cents);
        $this->assertSame(5500, $plan->price_cny_cents);
        $this->assertSame(2000000, $plan->price_tzs_cents);
        $this->assertTrue(AuditLog::where('action', 'plan.updated')->exists());
    }
}
