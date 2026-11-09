<?php

namespace Tests\Feature;

use App\Filament\Resources\Alerts\Pages\ListAlerts;
use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\Node;
use App\Models\User;
use App\Services\AlertAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Alerts reach the admins (email, Telegram) and the Alerts page works. */
class AlertDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'node-token-alerts-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
        config(['services.mvpn.payment_alert_emails' => 'ops@mbuniehub.com', 'services.brevo.key' => null]);
        Mail::fake();
        Http::fake();
    }

    private function node(): Node
    {
        return Node::create([
            'name' => 'Kuala Lumpur 1', 'region' => 'my', 'public_host' => 'n1.mbuniehub.com',
            'api_secret' => self::TOKEN, 'status' => 'online',
        ]);
    }

    public function test_a_new_critical_alert_is_emailed_once(): void
    {
        $analyzer = app(AlertAnalyzer::class);
        $analyzer->raise('critical', 'node.health', 'Node down', 'No reports.', $this->node());
        $analyzer->raise('critical', 'node.health', 'Node down', 'No reports.'); // still open: no repeat

        Mail::assertSent(AlertMail::class, 1);
        Mail::assertSent(AlertMail::class, fn (AlertMail $m) => $m->hasTo('ops@mbuniehub.com'));
        $this->assertSame(1, Alert::count());
    }

    public function test_info_alerts_are_not_emailed(): void
    {
        app(AlertAnalyzer::class)->raise('info', 'node.sync', 'FYI', 'Nothing to do.');

        Mail::assertNothingSent();
    }

    public function test_an_engine_down_report_from_a_node_emails_the_admins(): void
    {
        $this->node();

        $this->withToken(self::TOKEN)->postJson('/api/node/health', [
            'agent_version' => '0.2.0', 'applied_version' => -1, 'uptime_seconds' => 5, 'active_peers' => 0,
            'engines' => ['xray' => 'down', 'singbox' => 'up'], 'traffic' => [],
        ])->assertOk();

        $this->assertTrue(Alert::where('title', 'like', 'Engine down on Kuala Lumpur 1%')->exists());
        Mail::assertSent(AlertMail::class);
    }

    public function test_critical_alerts_go_to_telegram_as_html(): void
    {
        config(['services.telegram.bot_token' => 'bot-token', 'services.telegram.chat_id' => '42']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $r = app(AlertAnalyzer::class)->notify(new Alert([
            'severity' => 'critical', 'source' => 'node.health', 'title' => 'Node_1 *down*', 'body' => 'x < y',
        ]));

        $this->assertTrue($r['telegram']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org')
            && $req['parse_mode'] === 'HTML'
            && str_contains($req['text'], 'x &lt; y'));
    }

    public function test_the_alerts_page_has_tabs_status_and_a_test_button(): void
    {
        $admin = User::where('is_admin', true)->firstOrFail();
        Alert::create(['severity' => 'critical', 'source' => 'node.health', 'title' => 'Open problem']);
        Alert::create(['severity' => 'warn', 'source' => 'webhook', 'title' => 'Old problem', 'acknowledged_at' => now()]);

        $this->actingAs($admin)->get('/admin/alerts')
            ->assertSuccessful()
            ->assertSee('Open problem')
            ->assertDontSee('Old problem') // the Open tab is the default
            ->assertSee('Email alerts')
            ->assertSee('Network check')
            ->assertSee('Send a test alert');

        Livewire::actingAs($admin)->test(ListAlerts::class)
            ->callAction('test')
            ->assertNotified();

        Mail::assertSent(AlertMail::class, fn (AlertMail $m) => $m->alert->title === 'Test alert from the admin panel');
        $this->assertSame(2, Alert::count()); // the test alert is not saved
    }

    public function test_resolve_all_closes_every_open_alert(): void
    {
        $admin = User::where('is_admin', true)->firstOrFail();
        Alert::create(['severity' => 'critical', 'source' => 'node.health', 'title' => 'A']);
        Alert::create(['severity' => 'warn', 'source' => 'node.sync', 'title' => 'B']);

        Livewire::actingAs($admin)->test(ListAlerts::class)->callAction('resolveAll');

        $this->assertSame(0, Alert::open()->count());
    }
}
