<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Node;
use App\Models\Plan;
use App\Models\User;
use App\Services\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SingboxConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
    }

    private function activeSub(): \App\Models\Subscription
    {
        Node::create([
            'name' => 'Tokyo 1', 'region' => 'tk',
            'public_host' => 'n1.mbuniehub.com', 'api_base' => 'https://x',
            'api_secret' => Str::random(40),
            'reality_pubkey' => 'PBKEY', 'reality_short_id' => 'ab12',
            'reality_sni' => 'www.apple.com',
            'hysteria_port_range' => '20000-30000',
            'hysteria_cert_sha256' => 'AA:BB', 'status' => 'online',
        ]);

        $user = User::factory()->create(['phone' => '+8613800000000']);
        $plan = Plan::where('code', 'm1')->first();
        $inv = Invoice::create([
            'user_id' => $user->id, 'plan_code' => $plan->code, 'provider' => 'cryptomus',
            'currency' => 'cny', 'amount_cents' => $plan->price_cny_cents,
            'status' => 'paid', 'idempotency_key' => Str::uuid(), 'paid_at' => now(),
        ]);

        return app(ProvisioningService::class)->fulfill($inv);
    }

    public function test_singbox_endpoint_returns_a_runnable_config(): void
    {
        $sub = $this->activeSub();

        $resp = $this->getJson("/sub/{$sub->sub_token}?format=singbox&platform=windows")->assertOk();

        $cfg = $resp->json();

        $this->assertSame('tun', $cfg['inbounds'][0]['type']);
        $this->assertTrue($cfg['inbounds'][0]['auto_route']);
        $this->assertContains('192.168.0.0/16', $cfg['inbounds'][0]['route_exclude_address']);
        $this->assertContains('fe80::/10', $cfg['inbounds'][0]['route_exclude_address']);

        $tags = array_column($cfg['outbounds'], 'tag');
        $this->assertContains('proxy', $tags);
        $this->assertContains('auto', $tags);
        $this->assertContains('direct', $tags);

        // one vless + one hysteria2 peer outbound
        $types = array_column($cfg['outbounds'], 'type');
        $this->assertContains('vless', $types);
        $this->assertContains('hysteria2', $types);

        $vless = collect($cfg['outbounds'])->firstWhere('type', 'vless');
        $this->assertSame('www.apple.com', $vless['tls']['server_name']);
        $this->assertSame('PBKEY', $vless['tls']['reality']['public_key']);
        $this->assertTrue($vless['tls']['utls']['enabled']);

        $this->assertSame('proxy', $cfg['route']['final']);
        $this->assertSame('127.0.0.1:9095', $cfg['experimental']['clash_api']['external_controller']);
        $this->assertSame('https://www.apple.com/library/test/success.html', collect($cfg['outbounds'])->firstWhere('tag', 'auto')['url']);

        $this->assertSame('direct-dns', $cfg['dns']['rules'][0]['server']);
        $this->assertContains('n1.mbuniehub.com', $cfg['dns']['rules'][0]['domain']);
        $this->assertContains('n1.mbuniehub.com', $cfg['route']['rules'][2]['domain']);
        $this->assertFalse(collect($cfg['route']['rules'])->contains(fn ($rule) => ($rule['domain_suffix'] ?? null) === ['.cn']));
        $this->assertFalse(collect($cfg['dns']['rules'])->contains(fn ($rule) => ($rule['domain_suffix'] ?? null) === ['.cn']));
    }

    public function test_android_tun_excludes_debug_and_system_packages(): void
    {
        $sub = $this->activeSub();

        $resp = $this->getJson("/sub/{$sub->sub_token}?format=singbox&platform=android")->assertOk();

        $tun = $resp->json('inbounds.0');

        $this->assertArrayNotHasKey('interface_name', $tun);
        $this->assertArrayNotHasKey('strict_route', $tun);
        $this->assertContains('com.mbunie.mvpn', $tun['exclude_package']);
        $this->assertContains('com.android.shell', $tun['exclude_package']);
    }

    public function test_openvpn_protocol_returns_endpoint_config(): void
    {
        $sub = $this->activeSub();
        $node = $sub->peers()->with('node')->first()->node;
        $node->update([
            'openvpn_config' => implode("\n", [
                'client',
                'dev tun',
                'proto tcp-client',
                'remote asia8.libaivps.pw 443',
                'remote-cert-tls server',
                'cipher AES-256-GCM',
                'auth SHA256',
                '<ca>',
                '-----BEGIN CERTIFICATE-----',
                'MIIB',
                '-----END CERTIFICATE-----',
                '</ca>',
            ]),
            'openvpn_username' => 'user1',
            'openvpn_password' => 'pass1',
            'openvpn_server_name' => 'asia8.libaivps.pw',
        ]);

        $cfg = $this->getJson("/sub/{$sub->sub_token}?format=singbox&platform=android&protocol=openvpn")
            ->assertOk()
            ->json();

        $this->assertSame('openvpn-client', $cfg['endpoints'][0]['type']);
        $this->assertSame('asia8.libaivps.pw', $cfg['endpoints'][0]['server']);
        $this->assertSame(443, $cfg['endpoints'][0]['server_port']);
        $this->assertSame('tcp', $cfg['endpoints'][0]['network']);
        $this->assertSame('user1', $cfg['endpoints'][0]['username']);
        $this->assertArrayNotHasKey('cipher', $cfg['endpoints'][0]);
        $this->assertSame(['AES-256-GCM', 'AES-128-GCM'], $cfg['endpoints'][0]['data_ciphers']);
        $this->assertSame('ovpn-dns', $cfg['dns']['final']);
        $this->assertSame('direct', $cfg['route']['final']);
    }

    public function test_node_priority_controls_fallback_order(): void
    {
        $sub = $this->activeSub();
        $primary = $sub->peers()->with('node')->first()->node;
        $primary->update(['priority' => 10]);

        Node::create([
            'name' => 'Backup 1', 'region' => 'sg',
            'public_host' => 'n2.mbuniehub.com', 'api_base' => 'https://n2',
            'api_secret' => Str::random(40),
            'reality_pubkey' => 'PBKEY2', 'reality_short_id' => 'cd34',
            'reality_sni' => 'www.apple.com',
            'hysteria_port_range' => '21000-31000',
            'hysteria_cert_sha256' => 'CC:DD', 'status' => 'online',
            'priority' => 100,
        ]);

        app(ProvisioningService::class)->syncAllActive();

        $cfg = $this->getJson("/sub/{$sub->sub_token}?format=singbox&platform=android")->assertOk()->json();
        $auto = collect($cfg['outbounds'])->firstWhere('tag', 'auto');

        $this->assertStringStartsWith('Tokyo 1', $auto['outbounds'][0]);
        $this->assertStringStartsWith('Tokyo 1', $auto['outbounds'][1]);
        $this->assertStringStartsWith('Backup 1', $auto['outbounds'][2]);
        $this->assertStringStartsWith('Backup 1', $auto['outbounds'][3]);
    }

    public function test_default_format_still_returns_share_links(): void
    {
        $sub = $this->activeSub();

        $body = $this->get("/sub/{$sub->sub_token}")->assertOk()->getContent();
        $decoded = base64_decode($body);

        $this->assertStringContainsString('vless://', $decoded);
        $this->assertStringContainsString('hysteria2://', $decoded);
    }
}
