<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceDedupeTest extends TestCase
{
    use RefreshDatabase;

    private function subscriber(): User
    {
        $user = User::factory()->create();
        $user->subscriptions()->create([
            'plan_code' => 'm1', 'status' => 'active', 'max_devices' => 2,
            'started_at' => now(), 'expires_at' => now()->addDays(30),
        ]);

        return $user;
    }

    private function beat(User $user, array $payload, array $headers = [])
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($headers)
            ->postJson('/api/subscription/device', $payload + ['platform' => 'android']);
    }

    public function test_switching_to_the_hardware_id_keeps_one_row(): void
    {
        $user = $this->subscriber();
        $this->beat($user, ['fingerprint' => 'random-1', 'model' => 'OPPO PLA110'])->assertOk();

        $this->beat($user, ['fingerprint' => 'hw-abc', 'previous_fingerprint' => 'random-1', 'model' => 'OPPO PLA110'])->assertOk();

        $this->assertSame(1, Device::count());
        $this->assertSame('hw-abc', Device::first()->fingerprint);
    }

    public function test_reinstall_on_the_same_phone_reuses_its_row(): void
    {
        $user = $this->subscriber();
        $this->beat($user, ['fingerprint' => 'install-1', 'model' => 'samsung SM-G9960'])->assertOk();
        $this->beat($user, ['fingerprint' => 'install-2', 'model' => 'samsung SM-G9960'])->assertOk();
        // A different phone is still its own device.
        $this->beat($user, ['fingerprint' => 'pc-1', 'platform' => 'windows', 'model' => 'DESKTOP-1'])->assertOk();

        $this->assertSame(2, Device::count());
    }

    public function test_removed_phone_cannot_come_back_under_a_new_id(): void
    {
        $user = $this->subscriber();
        $this->beat($user, ['fingerprint' => 'random-1', 'model' => 'X'])->assertOk();
        Device::first()->update(['revoked_at' => now()]);

        $this->beat($user, ['fingerprint' => 'hw-abc', 'previous_fingerprint' => 'random-1', 'model' => 'X'],
            ['X-Mvpn-Device' => 'hw-abc', 'X-Mvpn-Device-Prev' => 'random-1'])
            ->assertForbidden()->assertJsonPath('error', 'device_revoked');
    }

    public function test_cleanup_command_keeps_one_row_per_phone(): void
    {
        $user = $this->subscriber();
        $sub = $user->activeSubscription();
        $mk = fn (array $a) => $sub->devices()->create($a + ['platform' => 'android']);

        $old1 = $mk(['fingerprint' => 'a', 'last_seen_at' => now()->subDays(2)]);            // no model, stale
        $oppoOld = $mk(['fingerprint' => 'b', 'model' => 'OPPO', 'last_seen_at' => now()->subDay()]);
        $oppoNew = $mk(['fingerprint' => 'c', 'model' => 'OPPO', 'last_seen_at' => now()]);
        $pc = $mk(['fingerprint' => 'd', 'platform' => 'windows', 'last_seen_at' => now()->subDays(3)]); // only windows row: kept
        $removed = $mk(['fingerprint' => 'e', 'model' => 'OPPO', 'last_seen_at' => now()->subDays(5), 'revoked_at' => now()]);

        $this->artisan('mvpn:dedupe-devices')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [$oppoNew->id, $pc->id, $removed->id],
            Device::pluck('id')->all(),
        );
    }
}
