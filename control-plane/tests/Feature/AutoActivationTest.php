<?php

namespace Tests\Feature;

use App\Jobs\ProvisionSubscriptionJob;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Plan;
use App\Models\User;
use App\Services\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
    }

    private function makeNode(string $region = 'hk'): Node
    {
        return Node::create([
            'name' => "Node-{$region}",
            'region' => $region,
            'public_host' => "{$region}.mbunievpn.com",
            'api_base' => 'https://x',
            'api_secret' => Str::random(40),
            'reality_pubkey' => 'PBK', 'reality_short_id' => 'sid',
            'reality_sni' => 'www.microsoft.com',
            'hysteria_port_range' => '20000-30000',
            'hysteria_cert_sha256' => 'AA:BB',
            'status' => 'online',
        ]);
    }

    public function test_paid_invoice_auto_activates_subscription_and_provisions_peers(): void
    {
        $node = $this->makeNode();
        $user = User::factory()->create(['phone' => '+255700000001']);
        $plan = Plan::where('code', 'm1')->first();

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'plan_code' => $plan->code,
            'provider' => 'cryptomus',
            'currency' => 'usd',
            'amount_cents' => $plan->price_usd_cents,
            'status' => 'paid',
            'idempotency_key' => Str::uuid(),
            'paid_at' => now(),
        ]);

        app(ProvisioningService::class)->fulfill($invoice);

        $sub = $user->fresh()->activeSubscription();
        $this->assertNotNull($sub);
        $this->assertSame('active', $sub->status);
        $this->assertTrue($sub->expires_at->isFuture());

        // one vless + one hysteria2 peer on the node
        $this->assertSame(2, $sub->peers()->where('status', 'active')->count());
        $this->assertSame(1, $node->fresh()->peer_version);
    }

    public function test_node_peers_endpoint_returns_active_peers_for_authenticated_node(): void
    {
        $node = $this->makeNode();
        $user = User::factory()->create(['phone' => '+255700000002']);
        $plan = Plan::where('code', 'm1')->first();

        $invoice = Invoice::create([
            'user_id' => $user->id, 'plan_code' => $plan->code, 'provider' => 'stripe',
            'currency' => 'usd', 'amount_cents' => $plan->price_usd_cents,
            'status' => 'paid', 'idempotency_key' => Str::uuid(), 'paid_at' => now(),
        ]);
        app(ProvisioningService::class)->fulfill($invoice);

        $resp = $this->withToken($node->api_secret)->getJson('/api/node/peers');

        $resp->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonCount(2, 'peers');
    }

    public function test_expired_subscription_is_swept_and_peers_disabled(): void
    {
        $node = $this->makeNode();
        $user = User::factory()->create(['phone' => '+255700000003']);
        $plan = Plan::where('code', 'm1')->first();

        $invoice = Invoice::create([
            'user_id' => $user->id, 'plan_code' => $plan->code, 'provider' => 'stripe',
            'currency' => 'usd', 'amount_cents' => $plan->price_usd_cents,
            'status' => 'paid', 'idempotency_key' => Str::uuid(), 'paid_at' => now(),
        ]);
        $sub = app(ProvisioningService::class)->fulfill($invoice);
        $sub->update(['expires_at' => now()->subDay()]);

        $this->artisan('mvpn:sweep-expired')->assertSuccessful();

        $this->assertSame('expired', $sub->fresh()->status);
        $this->assertSame(0, $sub->peers()->where('status', 'active')->count());
    }
}
