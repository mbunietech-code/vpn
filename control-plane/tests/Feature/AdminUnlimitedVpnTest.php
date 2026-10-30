<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUnlimitedVpnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
        Node::create([
            'name' => 'Tokyo 1', 'region' => 'tk', 'public_host' => 'n1.mbuniehub.com',
            'api_base' => 'https://x', 'api_secret' => Str::random(40),
            'reality_pubkey' => 'PBK', 'reality_short_id' => 'sid',
            'reality_sni' => 'www.apple.com', 'hysteria_port_range' => '20000-30000',
            'hysteria_cert_sha256' => 'AA', 'status' => 'online',
        ]);
    }

    public function test_an_admin_signing_in_gets_vpn_with_no_end_date(): void
    {
        $admin = User::factory()->create(['email' => 'staff@mbuniehub.com']);
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->getJson('/api/subscription')->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('unlimited', true)
            ->assertJsonPath('expires_at', null)
            ->assertJsonMissingPath('sub_url_missing');

        $sub = $admin->subscriptions()->sole();
        $this->assertTrue($sub->isUnlimited());
        $this->assertGreaterThan(0, $sub->peers()->where('status', 'active')->count());
        $this->assertNotNull($this->getJson('/api/subscription')->json('sub_url'));
    }

    public function test_an_expired_admin_subscription_comes_back_unlimited(): void
    {
        $admin = User::factory()->create();
        $old = $admin->subscriptions()->create([
            'plan_code' => 'm1', 'status' => 'expired', 'max_devices' => 1,
            'started_at' => now()->subMonths(2), 'expires_at' => now()->subDay(),
        ]);
        $admin->forceFill(['is_admin' => true])->save();

        $this->assertSame(1, $admin->subscriptions()->count());
        $old->refresh();
        $this->assertSame('active', $old->status);
        $this->assertTrue($old->isUnlimited());
    }

    public function test_customers_keep_their_dates_and_removing_admin_ends_the_unlimited_time(): void
    {
        $customer = User::factory()->create();
        Sanctum::actingAs($customer);
        $this->getJson('/api/subscription')->assertJsonPath('status', 'none');
        $this->assertSame(0, Subscription::count());

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->assertTrue($admin->subscriptions()->sole()->isUnlimited());

        $admin->forceFill(['is_admin' => false])->save();
        $sub = $admin->subscriptions()->sole();
        $this->assertFalse($sub->isUnlimited());
        $this->assertTrue($sub->expires_at->lte(now()));

        $this->artisan('mvpn:sweep-expired');
        $this->assertSame('expired', $sub->fresh()->status);
    }
}
