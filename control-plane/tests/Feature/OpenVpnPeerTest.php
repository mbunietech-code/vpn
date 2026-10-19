<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Peer;
use App\Models\User;
use App\Services\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenVpnPeerTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'node-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
    }

    private function provision(): array
    {
        $node = Node::create([
            'name' => 'Kuala Lumpur 1', 'region' => 'my',
            'public_host' => 'n1.mbuniehub.com', 'api_secret' => self::TOKEN,
            'status' => 'online',
        ]);
        $sub = User::factory()->create()->subscriptions()->create([
            'plan_code' => 'm1', 'status' => 'active', 'sub_token' => 'tokovpn123',
            'started_at' => now(), 'expires_at' => now()->addDays(30),
        ]);
        app(ProvisioningService::class)->syncPeers($sub);
        $peer = $sub->peers()->where('protocol', 'openvpn')->firstOrFail();

        return [$node, $sub, $peer];
    }

    private function profile(string $cn = 'mvpn-x'): string
    {
        return implode("\n", [
            'client', 'dev tun', 'remote n1.mbuniehub.com 443 tcp', 'remote n1.mbuniehub.com 1194 udp',
            'remote-cert-tls server', 'auth SHA256',
            '<ca>', 'CA-PEM', '</ca>', '<cert>', "CERT-{$cn}", '</cert>',
            '<key>', 'KEY', '</key>', '<tls-crypt>', 'TA', '</tls-crypt>',
        ]);
    }

    public function test_agent_sees_openvpn_peer_and_uploads_its_profile(): void
    {
        [$node, $sub, $peer] = $this->provision();

        $before = $this->withToken(self::TOKEN)->getJson('/api/node/peers')->assertOk();
        $row = collect($before->json('peers'))->firstWhere('protocol', 'openvpn');
        $this->assertSame($peer->remote_id, $row['remote_id']);
        $this->assertNull($row['openvpn_serial']);
        $this->assertNull($row['secret']);

        $this->withToken(self::TOKEN)->postJson('/api/node/openvpn-profile', [
            'remote_id' => $peer->remote_id, 'serial' => 'a1b2', 'profile' => $this->profile(),
        ])->assertOk();

        $peer->refresh();
        $this->assertSame('A1B2', $peer->openvpn_cert_serial);
        $this->assertStringContainsString('<key>', $peer->openvpn_profile);
        $this->assertArrayNotHasKey('openvpn_profile', $peer->toArray(), 'never serialised');

        // The new serial changes the version, so the agent re-checks once.
        $after = $this->withToken(self::TOKEN)->getJson('/api/node/peers')->json();
        $this->assertNotSame($before->json('version'), $after['version']);
        $this->assertSame('A1B2', collect($after['peers'])->firstWhere('protocol', 'openvpn')['openvpn_serial']);
    }

    public function test_profile_upload_is_validated(): void
    {
        [$node, $sub, $peer] = $this->provision();

        $this->withToken('wrong')->postJson('/api/node/openvpn-profile', [])->assertUnauthorized();
        $this->withToken(self::TOKEN)->postJson('/api/node/openvpn-profile', [
            'remote_id' => $peer->remote_id, 'serial' => 'AB', 'profile' => "client\n<ca>x</ca>",
        ])->assertStatus(422);
        $this->withToken(self::TOKEN)->postJson('/api/node/openvpn-profile', [
            'remote_id' => 'not-a-peer', 'serial' => 'AB', 'profile' => $this->profile(),
        ])->assertNotFound();
        $hy2 = $sub->peers()->where('protocol', 'hysteria2')->first();
        $this->withToken(self::TOKEN)->postJson('/api/node/openvpn-profile', [
            'remote_id' => $hy2->remote_id, 'serial' => 'AB', 'profile' => $this->profile(),
        ])->assertNotFound();
    }

    public function test_subscriber_gets_own_profile_over_shared_one(): void
    {
        [$node, $sub, $peer] = $this->provision();
        $node->update(['openvpn_config' => $this->profile('shared')]);

        // Before the agent uploads: the shared profile.
        $this->get('/sub/tokovpn123?format=ovpn')->assertOk()->assertSee('CERT-shared');

        $peer->update(['openvpn_profile' => $this->profile('own'), 'openvpn_cert_serial' => 'AB']);

        $this->get('/sub/tokovpn123?format=ovpn')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="mbunie-vpn.ovpn"')
            ->assertSee('CERT-own')->assertDontSee('CERT-shared');

        $cfg = $this->getJson('/sub/tokovpn123?format=singbox&platform=android&protocol=openvpn')->assertOk()->json();
        $this->assertSame('openvpn-client', $cfg['endpoints'][0]['type']);
        $this->assertSame(['CERT-own'], $cfg['endpoints'][0]['tls']['client_certificate']);
        $this->assertSame(443, $cfg['endpoints'][0]['server_port']);
        $this->assertSame('tcp', $cfg['endpoints'][0]['network']);
    }

    public function test_auto_config_offers_openvpn_as_a_server_but_not_in_auto(): void
    {
        [$node, $sub, $peer] = $this->provision();
        $peer->update(['openvpn_profile' => $this->profile('own'), 'openvpn_cert_serial' => 'AB']);

        $cfg = $this->getJson('/sub/tokovpn123?format=singbox&platform=windows')->assertOk()->json();

        $ep = $cfg['endpoints'][0];
        $this->assertSame('openvpn-client', $ep['type']);
        $this->assertSame('Kuala Lumpur 1 · OpenVPN', $ep['tag']);
        $this->assertFalse($ep['redirect_gateway'], 'only through the selector');
        $this->assertArrayNotHasKey('redirect_gateway_flags', $ep);
        $this->assertSame('tls_crypt', $ep['tls']['control_wrap']['type']);

        $outbounds = collect($cfg['outbounds'])->keyBy('tag');
        $this->assertContains('Kuala Lumpur 1 · OpenVPN', $outbounds['proxy']['outbounds']);
        $this->assertNotContains('Kuala Lumpur 1 · OpenVPN', $outbounds['auto']['outbounds']);
        $this->assertSame('auto', $outbounds['proxy']['default']);

        // iPhone (sing-box app, maybe older than 1.14) and explicit protocols: none.
        $ios = $this->getJson('/sub/tokovpn123?format=singbox&platform=ios')->json();
        $this->assertArrayNotHasKey('endpoints', $ios);
        $hy2 = $this->getJson('/sub/tokovpn123?format=singbox&platform=windows&protocol=hysteria2')->json();
        $this->assertArrayNotHasKey('endpoints', $hy2);
    }

    public function test_tls_auth_profiles_map_to_sing_box_names(): void
    {
        [$node, $sub, $peer] = $this->provision();
        $peer->update([
            'openvpn_profile' => str_replace(['<tls-crypt>', '</tls-crypt>'], ['<tls-auth>', '</tls-auth>'], $this->profile('own'))."\nkey-direction 1",
            'openvpn_cert_serial' => 'AB',
        ]);

        $ep = $this->getJson('/sub/tokovpn123?format=singbox&platform=android&protocol=openvpn')->json('endpoints.0');
        $this->assertSame(['type' => 'tls_auth', 'key' => ['TA'], 'direction' => 'client'], $ep['tls']['control_wrap']);
    }

    public function test_no_profile_anywhere_is_404_and_capacity_ignores_openvpn(): void
    {
        [$node, $sub, $peer] = $this->provision();

        $this->get('/sub/tokovpn123?format=ovpn')->assertNotFound();

        $node->update(['capacity' => 2]);
        $this->assertSame(3, Peer::where('node_id', $node->id)->where('status', 'active')->count());
        $this->assertFalse($node->hasCapacity(), 'two non-OpenVPN peers fill capacity 2');
        $node->update(['capacity' => 3]);
        $this->assertTrue($node->hasCapacity());
    }
}
