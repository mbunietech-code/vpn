<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The official sing-box app (iPhone) shows up under Admin › Devices. */
class SingboxAppDeviceTest extends TestCase
{
    use RefreshDatabase;

    private const IPHONE_UA = 'SFI (sing-box 1.14.8; language en_US)';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
    }

    private function activeSub(): Subscription
    {
        Node::create([
            'name' => 'Kuala Lumpur 1', 'region' => 'kl',
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

    private function iphoneProfile(Subscription $sub, array $headers = [])
    {
        return $this->withHeaders(['User-Agent' => self::IPHONE_UA] + $headers)
            ->get("/sub/{$sub->sub_token}?format=singbox&platform=ios&protocol=auto");
    }

    public function test_iphone_sing_box_app_is_listed_as_a_device(): void
    {
        $sub = $this->activeSub();

        $this->iphoneProfile($sub)->assertOk();
        $this->iphoneProfile($sub)->assertOk(); // a refresh updates the same row

        $device = $sub->devices()->sole();
        $this->assertSame('singbox-sfi', $device->fingerprint);
        $this->assertSame('ios', $device->platform);
        $this->assertSame('iPhone (sing-box app)', $device->model);
        $this->assertSame('sing-box 1.14.8', $device->app_version);
        $this->assertNotNull($device->last_seen_at);
        $this->assertFalse($device->vpn_connected);
    }

    public function test_mbunie_app_and_other_clients_are_not_added_here(): void
    {
        $sub = $this->activeSub();

        // Mbunie VPN sends its own device id and registers itself.
        $this->iphoneProfile($sub, ['X-Mvpn-Device' => 'abc'])->assertOk();
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->get("/sub/{$sub->sub_token}?format=singbox&platform=ios")->assertOk();

        $this->assertSame(0, Device::count());
    }

    public function test_a_removed_sing_box_app_gets_no_profile(): void
    {
        $sub = $this->activeSub();
        $this->iphoneProfile($sub)->assertOk();
        $sub->devices()->sole()->update(['revoked_at' => now()]);

        $this->iphoneProfile($sub)->assertForbidden();
    }
}
