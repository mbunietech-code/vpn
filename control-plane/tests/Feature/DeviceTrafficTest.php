<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Node;
use App\Models\Peer;
use App\Models\Subscription;
use App\Models\TrafficSample;
use App\Models\User;
use App\Services\TrafficRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeviceTrafficTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'traffic-node-token-0123456789';

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
        $this->node = Node::create([
            'name' => 'Kuala Lumpur 1', 'region' => 'my', 'public_host' => 'n1.traffic.test',
            'api_secret' => self::TOKEN, 'status' => 'provisioning',
        ]);
    }

    /** @return array{0: Subscription, 1: Peer, 2: Peer} */
    private function customer(string $email, string $hy2Id, string $vlessId): array
    {
        $user = User::factory()->create(['email' => $email]);
        $sub = $user->subscriptions()->create([
            'plan_code' => 'm1', 'status' => 'active', 'max_devices' => 2,
            'started_at' => now(), 'expires_at' => now()->addDays(30),
        ]);
        $hy2 = Peer::updateOrCreate(
            ['subscription_id' => $sub->id, 'node_id' => $this->node->id, 'protocol' => 'hysteria2'],
            ['remote_id' => $hy2Id, 'secret' => 'pw', 'status' => 'active'],
        );
        $vless = Peer::updateOrCreate(
            ['subscription_id' => $sub->id, 'node_id' => $this->node->id, 'protocol' => 'vless-reality'],
            ['remote_id' => $vlessId, 'status' => 'active'],
        );

        return [$sub, $hy2, $vless];
    }

    private function device(Subscription $sub, string $fp, array $attrs = []): Device
    {
        return $sub->devices()->create(array_merge([
            'fingerprint' => $fp, 'platform' => 'android', 'model' => 'Pixel '.$fp, 'last_seen_at' => now(),
        ], $attrs));
    }

    private function usage(array $sessions)
    {
        return $this->withToken(self::TOKEN)->postJson('/api/node/usage', [
            'window_seconds' => 60,
            'sessions' => $sessions,
            'unattributed' => [0, 0],
        ]);
    }

    private function admin(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    public function test_usage_requires_the_node_token(): void
    {
        $this->postJson('/api/node/usage', ['sessions' => []])->assertUnauthorized();
        $this->withToken('wrong')->postJson('/api/node/usage', ['sessions' => []])->assertUnauthorized();
    }

    public function test_usage_is_stored_per_session_in_five_minute_buckets(): void
    {
        Carbon::setTestNow('2026-11-16 10:01:30');
        [$sub, $hy2] = $this->customer('gao@example.com', 'hy2-gao', 'uuid-gao');
        $phone = $this->device($sub, 'a');

        $session = [
            'remote_id' => 'hy2-gao', 'protocol' => 'hysteria2', 'inbound' => 'hy2-obfs-in',
            'source_ip' => '114.80.1.2', 'up' => 1_000_000, 'down' => 30_000_000,
            'peak_up_mbps' => 1.5, 'peak_down_mbps' => 12.25, 'connections' => 7,
            'categories' => ['video' => [200_000, 25_000_000], 'calls' => [800_000, 5_000_000], 'bogus' => [1, 1]],
        ];
        $this->usage([$session])->assertOk()->assertJson(['stored' => 1]);

        // Next minute, same 5-minute bucket: bytes add up, peaks keep the max.
        Carbon::setTestNow('2026-11-16 10:02:30');
        $this->usage([array_merge($session, [
            'up' => 500_000, 'down' => 7_500_000, 'peak_down_mbps' => 3, 'connections' => 4,
            'categories' => ['calls' => [500_000, 7_500_000]],
        ])])->assertOk();

        $row = TrafficSample::sole();
        $this->assertSame($phone->id, $row->device_id, 'the only device of the subscription');
        $this->assertSame($hy2->id, $row->peer_id);
        $this->assertSame('2026-11-16 10:00:00', $row->bucket_at->format('Y-m-d H:i:s'));
        $this->assertSame(1_500_000, $row->bytes_up);
        $this->assertSame(37_500_000, $row->bytes_down);
        $this->assertSame(12.25, $row->peak_down_mbps);
        $this->assertSame(1.0, $row->last_down_mbps, '7.5 MB in 60 s = 1 Mbps');
        $this->assertSame(7, $row->connections);
        $this->assertSame([200_000, 25_000_000], $row->categories['video']);
        $this->assertSame([1_300_000, 12_500_000], $row->categories['calls']);
        $this->assertArrayNotHasKey('bogus', $row->categories);

        $this->assertSame(37_500_000, (int) $hy2->fresh()->bytes_down, 'peer totals grow too');

        // A new bucket starts at 10:05.
        Carbon::setTestNow('2026-11-16 10:05:10');
        $this->usage([$session])->assertOk();
        $this->assertSame(2, TrafficSample::count());
    }

    public function test_unknown_credentials_and_bad_payloads_are_ignored_or_rejected(): void
    {
        $this->customer('gao@example.com', 'hy2-gao', 'uuid-gao');

        $this->usage([['remote_id' => 'not-ours', 'protocol' => 'hysteria2', 'up' => 1, 'down' => 1]])
            ->assertOk()->assertJson(['stored' => 0]);
        $this->assertSame(0, TrafficSample::count());

        $this->usage([['remote_id' => 'hy2-gao', 'protocol' => 'wireguard', 'up' => 1, 'down' => 1]])->assertUnprocessable();
        $this->usage([['remote_id' => 'hy2-gao', 'protocol' => 'hysteria2', 'source_ip' => 'nope', 'up' => 1, 'down' => 1]])->assertUnprocessable();
    }

    public function test_two_devices_on_one_key_are_told_apart_by_their_app_ip(): void
    {
        [$sub] = $this->customer('gao@example.com', 'hy2-gao', 'uuid-gao');
        $phone = $this->device($sub, 'a', ['last_ip' => '114.80.1.2']);
        $laptop = $this->device($sub, 'b', ['last_ip' => '223.5.5.5']);

        $this->usage([
            ['remote_id' => 'uuid-gao', 'protocol' => 'vless-reality', 'inbound' => 'vless-reality', 'source_ip' => '223.5.5.5', 'up' => 10, 'down' => 900],
            ['remote_id' => 'hy2-gao', 'protocol' => 'hysteria2', 'inbound' => 'hy2-in', 'source_ip' => '114.80.1.2', 'up' => 20, 'down' => 100],
            ['remote_id' => 'hy2-gao', 'protocol' => 'hysteria2', 'inbound' => 'hy2-in', 'source_ip' => '36.1.1.1', 'up' => 5, 'down' => 50],
        ])->assertOk()->assertJson(['stored' => 3]);

        $this->assertSame($laptop->id, TrafficSample::where('source_ip', '223.5.5.5')->value('device_id'));
        $this->assertSame($phone->id, TrafficSample::where('source_ip', '114.80.1.2')->value('device_id'));
        $this->assertNull(TrafficSample::where('source_ip', '36.1.1.1')->value('device_id'), 'unknown IP, two devices: not guessed');

        // The phone's view: its own rows plus the subscription's unassigned ones.
        $rows = app(TrafficRecorder::class)->deviceQuery($phone, now()->subHour())->get();
        $this->assertEqualsCanonicalizing(['114.80.1.2', '36.1.1.1'], $rows->pluck('source_ip')->all());
    }

    public function test_heartbeat_records_the_app_ip(): void
    {
        $user = User::factory()->create(['phone' => '+255700000071']);
        $user->subscriptions()->create([
            'plan_code' => 'm1', 'status' => 'active', 'max_devices' => 2,
            'started_at' => now(), 'expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($user, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '114.80.9.9'])
            ->postJson('/api/subscription/device', ['fingerprint' => 'p1', 'platform' => 'android', 'connected' => true])
            ->assertOk();

        $this->assertSame('114.80.9.9', Device::sole()->last_ip);
    }

    public function test_category_totals_count_unsplit_bytes_as_unknown(): void
    {
        $samples = collect([
            new TrafficSample(['bytes_up' => 100, 'bytes_down' => 1000, 'categories' => ['video' => [50, 900]]]),
            new TrafficSample(['bytes_up' => 10, 'bytes_down' => 20, 'categories' => null]),
        ]);

        $this->assertSame([
            'video' => [50, 900],
            'unknown' => [60, 120],
        ], TrafficRecorder::categoryTotals($samples));
    }

    public function test_old_samples_are_pruned(): void
    {
        [$sub, $hy2] = $this->customer('gao@example.com', 'hy2-gao', 'uuid-gao');
        $base = ['node_id' => $this->node->id, 'peer_id' => $hy2->id, 'subscription_id' => $sub->id, 'protocol' => 'hysteria2'];
        TrafficSample::create($base + ['bucket_at' => now()->subHours(TrafficRecorder::RETENTION_HOURS + 1)]);
        TrafficSample::create($base + ['bucket_at' => now()->subHour()]);

        $this->artisan('mvpn:prune-traffic')->assertSuccessful();

        $this->assertSame(1, TrafficSample::count());
    }

    public function test_admin_sees_a_devices_traffic_and_speed(): void
    {
        [$sub] = $this->customer('gao@example.com', 'hy2-gao', 'uuid-gao');
        $phone = $this->device($sub, 'a');
        $this->usage([[
            'remote_id' => 'hy2-gao', 'protocol' => 'hysteria2', 'inbound' => 'hy2-obfs-in',
            'source_ip' => '240e:1::5', 'up' => 2_000_000, 'down' => 45_000_000,
            'peak_up_mbps' => 2, 'peak_down_mbps' => 18.4, 'connections' => 3,
            'categories' => ['video' => [100_000, 40_000_000], 'calls' => [1_900_000, 5_000_000]],
        ]])->assertOk();

        $this->actingAs($this->admin())
            ->get("/admin/devices/{$phone->id}/traffic")
            ->assertSuccessful()
            ->assertSee('Traffic · gao@example.com')
            ->assertSee('Hysteria2 Obfs · IPv6')
            ->assertSee('18.4 Mbps')
            ->assertSee('Video (YouTube, TikTok, …)')
            ->assertSee('Calls (WhatsApp, Zoom, …)')
            ->assertSee('240e:1::5')
            ->assertSee('Speed over time');

        // The list links each device to that page.
        $this->actingAs($this->admin())->get('/admin/devices')->assertSuccessful()
            ->assertSee("/admin/devices/{$phone->id}/traffic", false);
    }

    public function test_traffic_page_ranks_the_heaviest_devices(): void
    {
        [$gao] = $this->customer('gao@example.com', 'hy2-gao', 'uuid-gao');
        [$li] = $this->customer('li@example.com', 'hy2-li', 'uuid-li');
        $gaoPhone = $this->device($gao, 'a');
        $this->device($li, 'b');

        $this->usage([
            ['remote_id' => 'hy2-li', 'protocol' => 'hysteria2', 'inbound' => 'hy2-in', 'source_ip' => '36.1.1.1',
                'up' => 1_000, 'down' => 2_000_000, 'categories' => ['calls' => [1_000, 2_000_000]]],
            ['remote_id' => 'hy2-gao', 'protocol' => 'hysteria2', 'inbound' => 'hy2-in', 'source_ip' => '114.80.1.2',
                'up' => 5_000, 'down' => 900_000_000, 'peak_down_mbps' => 48.5, 'categories' => ['downloads' => [5_000, 900_000_000]]],
        ])->assertOk();

        $top = app(TrafficRecorder::class)->topConsumers(now()->subHour());
        $this->assertSame(['gao@example.com', 'li@example.com'], $top->pluck('customer')->all());
        $this->assertSame('downloads', $top[0]['top_category']);
        $this->assertSame($gaoPhone->id, $top[0]['device']->id);

        $this->actingAs($this->admin())->get('/admin/traffic')
            ->assertSuccessful()
            ->assertSeeInOrder(['Heaviest devices', 'gao@example.com', 'li@example.com'])
            ->assertSee('Downloads &amp; updates', false)
            ->assertSee('48.5 Mbps')
            ->assertSee("/admin/devices/{$gaoPhone->id}/traffic", false);
    }

    public function test_pages_load_with_no_traffic_yet(): void
    {
        [$sub] = $this->customer('gao@example.com', 'hy2-gao', 'uuid-gao');
        $phone = $this->device($sub, 'a');

        $this->actingAs($this->admin())->get('/admin/traffic')->assertSuccessful()->assertSee('No traffic reported');
        $this->actingAs($this->admin())->get("/admin/devices/{$phone->id}/traffic")->assertSuccessful()->assertSee('No traffic reported');
    }

    public function test_customers_cannot_open_the_traffic_pages(): void
    {
        [$sub] = $this->customer('gao@example.com', 'hy2-gao', 'uuid-gao');
        $phone = $this->device($sub, 'a');
        $customer = $sub->user;

        $this->actingAs($customer)->get('/admin/traffic')->assertForbidden();
        $this->actingAs($customer)->get("/admin/devices/{$phone->id}/traffic")->assertForbidden();
    }
}
