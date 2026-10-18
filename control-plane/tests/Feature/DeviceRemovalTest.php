<?php

namespace Tests\Feature;

use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\AuditLog;
use App\Models\Node;
use App\Models\Plan;
use App\Models\User;
use App\Services\SubscriptionAdmin;
use Database\Seeders\MvpnSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MvpnSeeder::class);
        $this->node = Node::create([
            'name' => 'KL1', 'region' => 'my', 'public_host' => 'n1.example.com',
            'api_secret' => Str::random(40), 'status' => 'online',
            'reality_pubkey' => 'PK', 'reality_short_id' => 'ab',
        ]);
    }

    public function test_removing_an_unknown_device_kicks_it_out_everywhere(): void
    {
        $cust = User::factory()->create();
        $sub = app(SubscriptionAdmin::class)->grant($cust, Plan::where('code', 'm1')->first(), 'test');
        $thief = $sub->devices()->create(['fingerprint' => 'stranger-phone', 'platform' => 'android', 'vpn_connected' => true, 'last_seen_at' => now()]);
        $sub->devices()->create(['fingerprint' => 'owner-pc', 'platform' => 'windows']);
        $token = $cust->createToken('app')->plainTextToken;

        $oldLink = $sub->sub_token;
        $oldIds = $sub->peers()->pluck('remote_id')->all();
        $oldVersion = $this->node->fresh()->peer_version;

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Livewire::test(ListDevices::class)
            ->callAction(TestAction::make('remove')->table($thief), ['reason' => 'not the customer'])
            ->assertHasNoActionErrors();

        $this->assertNotNull($thief->fresh()->revoked_at);
        $this->assertSame(0, $cust->tokens()->count(), 'customer signed out');
        $this->assertNotSame($oldLink, $sub->fresh()->sub_token, 'old /sub link dead');
        $this->assertEmpty(array_intersect($oldIds, $sub->peers()->pluck('remote_id')->all()), 'VPN keys rotated');
        $this->assertSame(3, $sub->peers()->where('status', 'active')->count(), 'owner keeps the plan');
        $this->assertGreaterThan($oldVersion, $this->node->fresh()->peer_version, 'node re-pulls peers');
        $this->assertTrue(AuditLog::where('action', 'device.removed')->exists());

        $this->app['auth']->forgetGuards();

        // The old link and the old app token are dead.
        $this->get("/sub/{$oldLink}")->assertNotFound();
        $this->withToken($token)->getJson('/api/subscription')->assertUnauthorized();

        // The removed phone can't even sign in again.
        $this->withHeader('X-Mvpn-Device', 'stranger-phone')
            ->postJson('/api/auth/otp/request', ['email' => $cust->email])
            ->assertForbidden()->assertJsonPath('error', 'device_revoked');

        // The owner's other device signs in again and gets the fresh link.
        $fresh = $cust->createToken('app')->plainTextToken;
        $this->withToken($fresh)->withHeader('X-Mvpn-Device', 'owner-pc')
            ->getJson('/api/subscription')->assertOk();
        $this->withHeader('X-Mvpn-Device', 'owner-pc')->get('/sub/'.$sub->fresh()->sub_token)->assertOk();
    }

    public function test_removed_device_can_be_allowed_again(): void
    {
        $cust = User::factory()->create();
        $sub = app(SubscriptionAdmin::class)->grant($cust, Plan::where('code', 'm1')->first(), 'test');
        $device = $sub->devices()->create(['fingerprint' => 'p1', 'platform' => 'android', 'revoked_at' => now()]);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Livewire::test(ListDevices::class)
            ->callAction(TestAction::make('allow')->table($device))
            ->assertHasNoActionErrors();

        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_admin_can_delete_devices_but_blocked_ones_are_kept(): void
    {
        $cust = User::factory()->create();
        $sub = app(SubscriptionAdmin::class)->grant($cust, Plan::where('code', 'm1')->first(), 'test');
        $old = $sub->devices()->create(['fingerprint' => 'old', 'platform' => 'android']);
        $other = $sub->devices()->create(['fingerprint' => 'other', 'platform' => 'android']);
        $blocked = $sub->devices()->create(['fingerprint' => 'bad', 'platform' => 'android', 'revoked_at' => now()]);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Livewire::test(ListDevices::class)
            ->callAction(TestAction::make('delete')->table($old))
            ->assertHasNoActionErrors();
        $this->assertModelMissing($old);
        $this->assertTrue(AuditLog::where('action', 'device.deleted')->exists());

        Livewire::test(ListDevices::class)
            ->selectTableRecords([$other->id, $blocked->id])
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertHasNoActionErrors();
        $this->assertModelMissing($other);
        $this->assertModelExists($blocked); // stays, so it stays blocked
    }
}
