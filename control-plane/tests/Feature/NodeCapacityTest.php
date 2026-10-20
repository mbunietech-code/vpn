<?php

namespace Tests\Feature;

use App\Mail\NodeCapacityMail;
use App\Models\Alert;
use App\Models\Node;
use App\Models\NodeTraffic;
use App\Models\User;
use App\Services\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NodeCapacityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'node-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
        Mail::fake();
        User::factory()->create(['is_admin' => true, 'email' => 'owner@example.com']);
    }

    private function node(array $attrs = []): Node
    {
        return Node::create(array_merge([
            'name' => 'Kuala Lumpur 1', 'region' => 'my', 'public_host' => 'n1.mbuniehub.com',
            'api_secret' => self::TOKEN, 'status' => 'online',
        ], $attrs));
    }

    private function health(array $net)
    {
        return $this->withToken(self::TOKEN)->postJson('/api/node/health', [
            'agent_version' => '0.4.0', 'engines' => ['xray' => 'up', 'singbox' => 'up'], 'net' => $net,
        ])->assertOk();
    }

    public function test_reports_add_up_per_day_and_keep_the_peak(): void
    {
        $node = $this->node();

        $this->health(['rx_bytes' => 1_000, 'tx_bytes' => 2_000, 'peak_rx_mbps' => 40, 'peak_tx_mbps' => 120.5]);
        $this->health(['rx_bytes' => 500, 'tx_bytes' => 700, 'peak_rx_mbps' => 10, 'peak_tx_mbps' => 80]);

        $day = NodeTraffic::where('node_id', $node->id)->sole();
        $this->assertSame(1_500, $day->rx_bytes);
        $this->assertSame(2_700, $day->tx_bytes);
        $this->assertSame(120.5, $day->peak_tx_mbps, 'peak keeps the busiest report');
        $this->assertSame(40.0, $day->peak_rx_mbps);
        $this->assertSame(4_200, $node->cycleBytes());
        $this->assertSame(120.5, $node->peakTxMbpsToday());
    }

    public function test_quota_alert_at_80_percent_is_raised_and_emailed_once(): void
    {
        $node = $this->node(['monthly_quota_gb' => 1]);

        $this->health(['rx_bytes' => 300_000_000, 'tx_bytes' => 400_000_000]); // 70%
        $this->assertSame(0, Alert::where('source', 'node.quota')->count());

        $this->health(['rx_bytes' => 50_000_000, 'tx_bytes' => 60_000_000]); // 81%
        $this->health(['rx_bytes' => 1, 'tx_bytes' => 1]);

        $alert = Alert::where('source', 'node.quota')->sole();
        $this->assertSame($node->id, $alert->node_id);
        $this->assertStringContainsString('80%', $alert->title);
        $this->assertSame(81.0, $node->quotaPercent());
        Mail::assertSent(NodeCapacityMail::class, 1);
        Mail::assertSent(NodeCapacityMail::class, fn ($m) => $m->hasTo('owner@example.com'));
    }

    public function test_subscriber_alert(): void
    {
        config(['services.mvpn.subscriber_alert' => 2]);
        $node = $this->node();
        foreach (range(1, 2) as $i) {
            $sub = User::factory()->create()->subscriptions()->create([
                'plan_code' => 'm1', 'status' => 'active', 'sub_token' => "tok{$i}",
                'started_at' => now(), 'expires_at' => now()->addDays(30),
            ]);
            app(ProvisioningService::class)->syncPeers($sub);
        }

        $this->assertSame(2, $node->activeSubscriptionCount(), 'three peers each, counted once');
        $this->health(['rx_bytes' => 1, 'tx_bytes' => 1]);

        $this->assertTrue(Alert::where('source', 'node.subscribers')->where('node_id', $node->id)->exists());
        Mail::assertSent(NodeCapacityMail::class, 1);
    }

    public function test_quota_cycle_follows_the_reset_day(): void
    {
        $node = $this->node(['quota_reset_day' => 15]);

        $this->assertSame('2026-10-15', $node->quotaCycleStart(Carbon::parse('2026-10-20'))->toDateString());
        $this->assertSame('2026-09-15', $node->quotaCycleStart(Carbon::parse('2026-10-03'))->toDateString());

        // Older traffic is outside the cycle.
        Carbon::setTestNow('2026-10-20 12:00:00');
        NodeTraffic::create(['node_id' => $node->id, 'day' => '2026-10-10', 'rx_bytes' => 999, 'tx_bytes' => 999]);
        NodeTraffic::create(['node_id' => $node->id, 'day' => '2026-10-16', 'rx_bytes' => 10, 'tx_bytes' => 20]);
        $this->assertSame(30, $node->cycleBytes());
        Carbon::setTestNow();
    }

    public function test_nodes_page_shows_the_new_columns(): void
    {
        $node = $this->node(['monthly_quota_gb' => 8000]);
        $this->health(['rx_bytes' => 1_000_000_000_000, 'tx_bytes' => 1_000_000_000_000, 'peak_tx_mbps' => 340]);
        $this->actingAs(User::where('is_admin', true)->first());

        $this->get('/admin/nodes')->assertOk()
            ->assertSee('Data this month')
            ->assertSee('2 TB / 8 TB')
            ->assertSee('peak today 340 Mbps')
            ->assertSee('Subscribers');
        $this->get("/admin/nodes/{$node->id}/edit")->assertOk()->assertSee('Monthly data quota (GB)');
    }
}
