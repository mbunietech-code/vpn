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
