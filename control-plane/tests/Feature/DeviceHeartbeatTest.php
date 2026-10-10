<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function subscriber(int $maxDevices = 2): User
    {
        $user = User::factory()->create(['phone' => '+255700000077']);
        $user->subscriptions()->create([
            'plan_code' => 'm1',
            'status' => 'active',
            'max_devices' => $maxDevices,
            'started_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        return $user;
    }

    private function beat(User $user, array $payload)
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/subscription/device', array_merge([
            'fingerprint' => 'phone-1',
            'platform' => 'android',
        ], $payload));
    }

    public function test_heartbeat_marks_device_online_then_offline(): void
    {
        $user = $this->subscriber();

        $this->beat($user, ['connected' => true, 'protocol' => 'auto', 'app_version' => '1.0.4'])->assertOk();
        $device = Device::firstOrFail();
        $this->assertTrue($device->isOnline());
        $this->assertSame('1.0.4', $device->app_version);
        $since = $device->connected_at;

        // A later beat keeps the original connect time.
        $this->travel(2)->minutes();
        $this->beat($user, ['connected' => true])->assertOk();
        $this->assertEquals($since, $device->fresh()->connected_at);
        $this->assertSame(1, Device::online()->count());

        $this->beat($user, ['connected' => false])->assertOk();
        $this->assertFalse($device->fresh()->isOnline());
        $this->assertNull($device->fresh()->connected_at);
    }

    public function test_silent_device_drops_offline_after_window(): void
    {
        $user = $this->subscriber();
        $this->beat($user, ['connected' => true])->assertOk();

        $this->travel(Device::ONLINE_WINDOW_MINUTES + 1)->minutes();

        $this->assertSame(0, Device::online()->count());
    }

    public function test_revoked_device_cannot_unrevoke_itself(): void
    {
        $user = $this->subscriber();
        $this->beat($user, ['connected' => true])->assertOk();
        Device::firstOrFail()->update(['revoked_at' => now()]);

        $this->beat($user, ['connected' => true])->assertForbidden()->assertJson(['error' => 'device_revoked']);
        $this->assertNotNull(Device::firstOrFail()->revoked_at);
    }

    public function test_device_over_limit_is_recorded_and_reported(): void
    {
        $user = $this->subscriber(maxDevices: 1);
        $this->beat($user, ['fingerprint' => 'pc-1', 'platform' => 'windows'])->assertOk();

        $this->beat($user, ['fingerprint' => 'phone-2', 'connected' => true])
            ->assertStatus(409)->assertJson(['error' => 'device_limit_reached', 'limit' => 1]);

        $this->assertSame(2, Device::count(), 'admin still sees the extra device');
    }
}
