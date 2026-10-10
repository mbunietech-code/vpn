<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Peer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeAgentTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'node-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
    }

    private function freshNode(array $attrs = []): Node
    {
        return Node::create(array_merge([
            'name' => 'Kuala Lumpur 1',
            'region' => 'my',
            'public_host' => 'n1.mbuniehub.com',
            'api_secret' => self::TOKEN,
            'status' => 'provisioning',
        ], $attrs));
    }

    private function activeSubscription(): \App\Models\Subscription
    {
        $user = User::factory()->create(['phone' => '+255700000009']);

        return $user->subscriptions()->create([
            'plan_code' => 'm1',
            'status' => 'active',
            'max_devices' => 2,
            'started_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    private function health(array $payload = [])
    {
        return $this->withToken(self::TOKEN)->postJson('/api/node/health', array_merge([
            'agent_version' => '0.2.0',
            'applied_version' => -1,
            'uptime_seconds' => 5,
            'active_peers' => 0,
            'engines' => ['xray' => 'up', 'singbox' => 'up'],
            'traffic' => [],
        ], $payload));
    }

    public function test_rejects_unknown_token(): void
    {
        $this->freshNode();

        $this->withToken('wrong')->getJson('/api/node/peers')->assertUnauthorized();
        $this->withToken('wrong')->postJson('/api/node/health', [])->assertUnauthorized();
    }

    public function test_first_health_registers_params_and_provisions_existing_subscribers(): void
    {
        $sub = $this->activeSubscription();
        $node = $this->freshNode();
        $this->assertSame(0, Peer::count(), 'provisioning node gets no peers yet');

        $this->health(['node_info' => [
            'public_host' => 'ignored.example.com',
            'reality_pubkey' => 'PUBKEY123',
            'reality_short_id' => 'abcd1234',
            'reality_sni' => 'www.microsoft.com',
            'reality_port' => 8443,
            'hysteria_port' => 8443,
            'hysteria_port_range' => '20000-30000',
            'hysteria_cert_sha256' => 'AA:BB:CC',
            'hysteria_cert_pem' => "-----BEGIN CERTIFICATE-----\nX\n-----END CERTIFICATE-----\n",
        ]])->assertOk();

        $node->refresh();
        $this->assertSame('online', $node->status);
        $this->assertSame('PUBKEY123', $node->reality_pubkey);
        $this->assertSame('abcd1234', $node->reality_short_id);
        $this->assertSame('AA:BB:CC', $node->hysteria_cert_sha256);
        $this->assertSame('0.2.0', $node->agent_version);
        $this->assertSame('n1.mbuniehub.com', $node->public_host, 'admin-set host is kept');

        // The subscriber who paid before the node existed now has both peers.
        $this->assertSame(2, $sub->peers()->where('node_id', $node->id)->where('status', 'active')->count());

        $res = $this->withToken(self::TOKEN)->getJson('/api/node/peers')->assertOk();
        $this->assertCount(2, $res->json('peers'));
        $this->assertGreaterThan(0, $res->json('version'));
        $this->assertEqualsCanonicalizing(['vless-reality', 'hysteria2'], array_column($res->json('peers'), 'protocol'));

        // Clients are pointed at the node's own port, not a hard-coded 443.
        $builder = app(\App\Services\SubscriptionBuilder::class);
        $links = base64_decode($builder->build($sub->fresh()));
        $this->assertStringContainsString('@n1.mbuniehub.com:8443?', $links);
        $ports = collect($builder->buildSingbox($sub->fresh())['outbounds'])
            ->whereIn('type', ['vless', 'hysteria2'])->pluck('server_port')->unique()->values()->all();
        $this->assertSame([8443], $ports);
    }

    public function test_expiry_changes_peer_list_version_without_the_sweep(): void
    {
        $sub = $this->activeSubscription();
        $this->freshNode();
        $this->health(['node_info' => ['reality_pubkey' => 'PUBKEY123', 'reality_short_id' => 'abcd1234']])->assertOk();

        $before = $this->withToken(self::TOKEN)->getJson('/api/node/peers')->assertOk();
        $this->assertCount(2, $before->json('peers'));

        // Lapses, but no cron has run mvpn:sweep-expired yet.
        $sub->update(['expires_at' => now()->subMinute()]);

        $after = $this->withToken(self::TOKEN)->getJson('/api/node/peers')->assertOk();
        $this->assertCount(0, $after->json('peers'));
        $this->assertNotSame($before->json('version'), $after->json('version'), 'agent must re-apply');
    }

    public function test_health_never_overrides_admin_draining_or_disabled(): void
    {
        foreach (['draining', 'disabled'] as $status) {
            Node::query()->delete();
            $node = $this->freshNode(['status' => $status]);

            $this->health()->assertOk();

            $this->assertSame($status, $node->fresh()->status);
            $this->assertNotNull($node->fresh()->last_health_at);
        }
    }

    public function test_engine_down_marks_degraded_and_agent_error_raises_alert(): void
    {
        $node = $this->freshNode(['status' => 'online']);

        $this->health([
            'engines' => ['xray' => 'down', 'singbox' => 'up'],
            'last_error' => 'xray run -test: bad config',
        ])->assertOk();

        $this->assertSame('degraded', $node->fresh()->status);
        $this->assertDatabaseHas('alerts', ['source' => 'node.sync', 'node_id' => $node->id]);
        $this->assertDatabaseHas('alerts', ['source' => 'node.health', 'node_id' => $node->id]);
    }

    public function test_sync_peers_command_provisions_active_subscriptions(): void
    {
        $node = $this->freshNode(['status' => 'online']);
        $sub = $this->activeSubscription();
        $this->assertSame(0, $sub->peers()->count());

        $this->artisan('mvpn:sync-peers')->assertSuccessful();

        $this->assertSame(2, $sub->peers()->where('node_id', $node->id)->count());
    }
}
