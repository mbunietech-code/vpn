<?php

namespace Tests\Feature;

use App\Filament\Resources\VipEvents\Pages\CreateVipEvent;
use App\Filament\Resources\VipEvents\Pages\EditVipEvent;
use App\Filament\Resources\VipEvents\Pages\ListVipEvents;
use App\Filament\Resources\VipEvents\RelationManagers\PassesRelationManager;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\PaymentMethod;
use App\Models\Peer;
use App\Models\User;
use App\Models\VipEvent;
use App\Models\VipPass;
use App\Services\VipPasses;
use Database\Seeders\MvpnSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * VIP event passes: QR page → pay per device (Pesapal / Stripe / manual
 * Alipay) → pass page → the app signs in with the code → access ends with
 * the event, and the admin can switch everything off.
 */
class VipEventTest extends TestCase
{
    use RefreshDatabase;

    private VipEvent $event;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MvpnSeeder::class);
        config([
            'services.pesapal.consumer_key' => 'ck_test',
            'services.pesapal.consumer_secret' => 'cs_test',
            'services.pesapal.currency' => 'usd',
            'services.pesapal.ipn_id' => 'ipn-123',
            'services.stripe.secret' => 'sk_test_x',
            'services.stripe.webhook_secret' => 'whsec_x',
        ]);
        $this->node = Node::create([
            'name' => 'KL1', 'region' => 'my', 'public_host' => 'n1.example.com',
            'api_secret' => Str::random(40), 'status' => 'online',
            'reality_pubkey' => 'PK', 'reality_short_id' => 'ab',
        ]);
        $this->event = VipEvent::create([
            'name' => 'Beijing Forum',
            'slug' => 'beijing-forum',
            'price_cny_cents' => 2500,
            'price_usd_cents' => 350,
            'price_tzs_cents' => 900000,
            'max_devices_per_order' => 5,
            'ends_at' => now()->addDays(3),
        ]);
    }

    /** What Pesapal's status API answers right now (tests change it as the buyer pays). */
    private array $pesapal = ['code' => '0', 'amount' => 0.0, 'currency' => 'USD'];

    private bool $pesapalFaked = false;

    private function fakePesapal(string $statusCode = '1', float $amount = 7.0, string $currency = 'USD'): void
    {
        $this->pesapal = ['code' => $statusCode, 'amount' => $amount, 'currency' => $currency];
        if ($this->pesapalFaked) {
            return; // Http::fake stubs stack: the first one registered keeps answering
        }
        $this->pesapalFaked = true;
        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'tok', 'status' => '200']),
            '*/api/Transactions/SubmitOrderRequest' => Http::response([
                'order_tracking_id' => 'trk-1', 'merchant_reference' => 'x',
                'redirect_url' => 'https://pay.pesapal.com/iframe/trk-1', 'status' => '200',
            ]),
            '*/api/Transactions/GetTransactionStatus*' => fn () => Http::response([
                'status_code' => (int) $this->pesapal['code'], 'amount' => $this->pesapal['amount'],
                'currency' => $this->pesapal['currency'],
                'merchant_reference' => 'MVPN'.Invoice::latest('id')->value('id'),
                'payment_status_description' => $this->pesapal['code'] === '1' ? 'Completed' : 'Pending',
            ]),
        ]);
    }

    /** Buy N devices with Pesapal; returns the pending pass. */
    private function buyWithPesapal(int $devices = 2): VipPass
    {
        $this->fakePesapal('0');

        $this->get('/vip/beijing-forum')->assertOk()->assertSee('Beijing Forum')->assertSee('¥25');

        $this->post('/vip/beijing-forum/checkout', ['devices' => $devices, 'method' => 'pesapal', 'contact' => 'guest@example.com'])
            ->assertRedirect('https://pay.pesapal.com/iframe/trk-1');

        $pass = VipPass::latest('id')->firstOrFail();
        $this->assertSame('pending', $pass->status);
        $this->assertSame($devices, $pass->devices);
        $this->assertSame(350 * $devices, $pass->invoice->amount_cents);
        $this->assertSame('usd', $pass->invoice->currency);
        $this->assertSame('vip', $pass->invoice->plan_code);
        $this->assertSame('trk-1', $pass->invoice->provider_ref);
        $this->assertNull($pass->user->email);

        return $pass;
    }

    private function ipn(): \Illuminate\Testing\TestResponse
    {
        return $this->get('/webhooks/pesapal?OrderTrackingId=trk-1&OrderMerchantReference=MVPN'
            .Invoice::latest('id')->value('id').'&OrderNotificationType=IPNCHANGE');
    }

    private function claim(string $code, string $device)
    {
        return $this->withHeaders(['X-Mvpn-Device' => $device])
            ->postJson('/api/auth/vip', ['code' => $code, 'device_name' => 'android device', 'platform' => 'android']);
    }

    public function test_pesapal_ipn_activates_the_pass_until_the_event_ends(): void
    {
        $pass = $this->buyWithPesapal(2);

        // Pass page while unpaid: waiting, no code shown.
        $this->get($pass->pageUrl())->assertOk()->assertSee('Waiting for your payment')->assertDontSee($pass->displayCode());

        $this->fakePesapal('1', 7.0);
        $this->ipn()->assertOk()->assertJsonPath('status', 200)->assertJsonPath('orderTrackingId', 'trk-1');

        $pass->refresh();
        $this->assertSame('active', $pass->status);
        $sub = $pass->subscription;
        $this->assertTrue($sub->isActive());
        $this->assertSame(2, $sub->max_devices);
        $this->assertSame($this->event->ends_at->timestamp, $sub->expires_at->timestamp);
        $this->assertSame('paid', $pass->invoice->fresh()->status);
        $this->assertTrue(Peer::where('subscription_id', $sub->id)->where('status', 'active')->exists());

        // The pass page now shows the code and the app download.
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14)')->get($pass->pageUrl())->assertOk()
            ->assertSee($pass->displayCode())
            ->assertSee('mvpnvip://vip?code='.$pass->code, false)
            // Only the VIP app is offered, never the normal one.
            ->assertSee('Mbunie-VIP-', false)
            ->assertDontSee('Mbunie-VPN-', false);

        // A repeated IPN changes nothing.
        $this->ipn()->assertOk();
        $this->assertSame(1, \App\Models\Subscription::where('user_id', $pass->user_id)->count());
    }

    public function test_pass_page_confirms_the_payment_itself_when_the_ipn_is_late(): void
    {
        $pass = $this->buyWithPesapal(1);
        $this->fakePesapal('1', 3.5);

        $this->getJson(route('vip.pass.status', $pass->page_token))->assertOk()->assertJsonPath('state', 'active');
        $this->assertSame('active', $pass->fresh()->status);
    }

    public function test_underpaid_order_does_not_activate(): void
    {
        $pass = $this->buyWithPesapal(2);
        $this->fakePesapal('1', 3.5); // paid for one device, asked for two

        $this->ipn()->assertOk();
        $this->assertSame('pending', $pass->fresh()->status);
    }

    public function test_code_signs_in_up_to_the_paid_number_of_devices(): void
    {
        $pass = app(VipPasses::class)->give($this->event, 2, 'speaker', 'admin:1');

        $first = $this->claim(strtolower($pass->displayCode()), 'dev-a')->assertOk()->json();
        $this->assertNotEmpty($first['token']);
        $this->assertSame('VIP · Beijing Forum', $first['user']['identifier']);

        // Same phone again (reinstall): fine, no extra seat used.
        $this->claim($pass->code, 'dev-a')->assertOk();
        // The clipboard form the pass page copies works too.
        $this->claim('MVPN-VIP:'.$pass->code, 'dev-b')->assertOk();
        // Third device: refused.
        $this->claim($pass->code, 'dev-c')->assertStatus(409)->assertJsonPath('error', 'pass_full');

        $this->assertSame(2, Device::where('subscription_id', $pass->subscription_id)->count());

        // Signed in: the app sees an active VIP subscription with a sub link.
        $this->withHeaders(['Authorization' => 'Bearer '.$first['token'], 'X-Mvpn-Device' => 'dev-a'])
            ->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('plan', 'vip');

        $this->claim('ZZZZ-ZZZZ', 'dev-d')->assertStatus(404)->assertJsonPath('error', 'invalid_code');
    }

    public function test_unpaid_pass_cannot_sign_in(): void
    {
        $pass = $this->buyWithPesapal(1);

        $this->claim($pass->code, 'dev-a')->assertStatus(403)->assertJsonPath('error', 'pass_pending');
    }

    public function test_switching_the_event_off_and_moving_the_end_time_follow_every_pass(): void
    {
        $a = app(VipPasses::class)->give($this->event, 1, null, 'admin:1');
        $b = app(VipPasses::class)->give($this->event, 1, null, 'admin:1');

        $this->event->update(['access_enabled' => false]);
        foreach ([$a, $b] as $p) {
            $sub = $p->fresh()->subscription;
            $this->assertSame('suspended', $sub->status);
            $this->assertFalse(Peer::where('subscription_id', $sub->id)->where('status', 'active')->exists());
            $this->assertSame('off', $p->fresh()->state());
        }
        $this->claim($a->code, 'dev-x')->assertStatus(403)->assertJsonPath('error', 'pass_off');

        $this->event->update(['access_enabled' => true]);
        $this->assertTrue($a->fresh()->subscription->isActive());

        // Longer event: every pass follows.
        $later = now()->addDays(10)->startOfMinute();
        $this->event->update(['ends_at' => $later]);
        $this->assertSame($later->timestamp, $b->fresh()->subscription->expires_at->timestamp);

        // Event over: expired, and the app can't sign in any more.
        $this->event->update(['ends_at' => now()->subMinute()]);
        $this->assertSame('expired', $a->fresh()->subscription->status);
        $this->claim($a->code, 'dev-y')->assertStatus(403)->assertJsonPath('error', 'pass_ended');
    }

    public function test_blocking_one_pass_leaves_the_others_on(): void
    {
        $a = app(VipPasses::class)->give($this->event, 1, null, 'admin:1');
        $b = app(VipPasses::class)->give($this->event, 1, null, 'admin:1');

        app(VipPasses::class)->setBlocked($a, true, 'admin:1');
        $this->assertSame('suspended', $a->fresh()->subscription->status);
        $this->assertTrue($b->fresh()->subscription->isActive());

        // The event switched off and on again does not wake a blocked pass.
        $this->event->update(['access_enabled' => false]);
        $this->event->update(['access_enabled' => true]);
        $this->assertSame('suspended', $a->fresh()->subscription->status);
        $this->assertTrue($b->fresh()->subscription->isActive());
    }

    public function test_manual_alipay_payment_is_activated_when_the_admin_approves(): void
    {
        Storage::fake();
        PaymentMethod::create(['type' => 'alipay', 'label' => 'Alipay (¥)', 'currency' => 'cny', 'is_active' => true]);
        $method = PaymentMethod::where('label', 'Alipay (¥)')->first();

        $this->post('/vip/beijing-forum/checkout', ['devices' => 3, 'method' => 'manual:'.$method->id])
            ->assertRedirect();
        $pass = VipPass::latest('id')->first();
        $this->assertSame(7500, $pass->invoice->amount_cents);
        $this->assertSame('cny', $pass->invoice->currency);

        $this->get($pass->pageUrl())->assertOk()->assertSee('Payment screenshot');

        $this->post(route('vip.pass.proof', $pass->page_token), [
            'proof' => UploadedFile::fake()->image('paid.png'),
        ])->assertRedirect($pass->pageUrl());
        $this->assertSame('review', $pass->fresh()->state());

        app(\App\Services\ManualPaymentService::class)->approve($pass->invoice->fresh(), 'admin:1');

        $this->assertSame('active', $pass->fresh()->state());
        $this->assertSame(3, $pass->fresh()->subscription->max_devices);
    }

    public function test_stripe_return_page_confirms_the_session(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_vip', 'url' => 'https://checkout.stripe.com/c/pay/cs_vip']),
            'api.stripe.com/v1/checkout/sessions/cs_vip' => Http::response([
                'id' => 'cs_vip', 'payment_status' => 'paid', 'amount_total' => 2500, 'currency' => 'cny',
            ]),
        ]);

        $this->post('/vip/beijing-forum/checkout', ['devices' => 1, 'method' => 'stripe'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_vip');
        $pass = VipPass::latest('id')->first();
        $this->assertSame('cny', $pass->invoice->currency);
        $this->assertSame(2500, $pass->invoice->amount_cents);

        // Stripe sends the buyer back to their pass page.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'checkout/sessions')
            && str_contains(urldecode($r->body()), $pass->pageUrl()));

        $this->get($pass->pageUrl())->assertOk()->assertSee($pass->displayCode());
    }

    public function test_closed_event_does_not_sell(): void
    {
        $this->event->update(['sales_open' => false]);

        $this->get('/vip/beijing-forum')->assertOk()->assertSee('closed');
        $this->post('/vip/beijing-forum/checkout', ['devices' => 1, 'method' => 'pesapal'])->assertStatus(410);
    }

    public function test_methods_without_keys_or_price_are_not_offered(): void
    {
        config(['services.pesapal.consumer_key' => '']);
        $this->event->update(['price_cny_cents' => 0]); // Stripe charges CNY

        $keys = array_column($this->event->fresh()->paymentOptions(), 'key');
        $this->assertNotContains('pesapal', $keys);
        $this->assertNotContains('stripe', $keys);
    }

    public function test_pages_show_in_chinese(): void
    {
        $this->get('/vip/beijing-forum?lang=zh')->assertOk()->assertSee('每台设备');
        $this->withHeader('Accept-Language', 'sw-TZ,sw;q=0.9')->get('/vip/beijing-forum')->assertSee('kwa kifaa');
    }

    public function test_admin_creates_an_event_and_gives_passes(): void
    {
        $admin = User::where('is_admin', true)->first();
        $this->actingAs($admin);

        $this->get('/admin/vip-events')->assertOk();
        $this->get('/admin/vip-events/create')->assertOk();

        Livewire::test(CreateVipEvent::class)
            ->fillForm([
                'name' => 'Shanghai Expo',
                'slug' => 'shanghai-expo',
                'ends_at' => now('Asia/Shanghai')->addDays(2)->format('Y-m-d H:i'),
                'timezone' => 'Asia/Shanghai',
                'price_cny_cents' => 25,
                'price_usd_cents' => 3.5,
                'price_tzs_cents' => 9000,
                'max_devices_per_order' => 4,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $event = VipEvent::where('slug', 'shanghai-expo')->firstOrFail();
        $this->assertSame(2500, $event->price_cny_cents);
        $this->assertSame(350, $event->price_usd_cents);

        $this->get('/admin/vip-events/'.$event->id.'/edit')->assertOk()->assertSee('Passes');

        Livewire::test(ListVipEvents::class)
            ->callTableAction('give', $event, ['count' => 2, 'devices' => 3, 'note' => 'press'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(2, $event->passes()->where('status', 'active')->count());
        $this->assertSame(3, $event->passes()->first()->subscription->max_devices);

        Livewire::test(EditVipEvent::class, ['record' => $event->id])
            ->callAction('toggleAccess');
        $this->assertFalse($event->fresh()->access_enabled);

        Livewire::test(PassesRelationManager::class, ['ownerRecord' => $event->fresh(), 'pageClass' => EditVipEvent::class])
            ->assertCanSeeTableRecords($event->passes()->get())
            ->callTableAction('give', null, ['count' => 1, 'devices' => 1])
            ->assertHasNoTableActionErrors();
        $this->assertSame(3, $event->passes()->count());

        $this->get('/vip/shanghai-expo/qr.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_admin_sets_the_vip_app_links_in_settings(): void
    {
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $this->actingAs(User::where('is_admin', true)->first());

        $this->get('/admin/settings?tab=app')->assertOk()->assertSee('VIP app downloads');

        // The default (a path on this server) is shown and saving it changes nothing.
        Livewire::test(\App\Filament\Resources\Settings\Pages\ManageSettings::class)
            ->assertSet('data.v_vip_android_link', config('services.mvpn.download_vip_android'))
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull(\App\Models\Setting::value('vip_android_link'));

        Livewire::test(\App\Filament\Resources\Settings\Pages\ManageSettings::class)
            ->fillForm(['v_vip_android_link' => 'javascript:alert(1)'])
            ->call('save')
            ->assertHasFormErrors(['v_vip_android_link']);

        Livewire::test(\App\Filament\Resources\Settings\Pages\ManageSettings::class)
            ->fillForm(['v_vip_android_link' => 'https://files.example.com/vip.apk', 'v_vip_windows_link' => 'off'])
            ->call('save')
            ->assertHasNoFormErrors();

        // The next request reads the saved links (applied at boot).
        (new \App\Providers\SettingsServiceProvider($this->app))->boot();
        $pass = app(VipPasses::class)->give($this->event, 1, null, 'admin:1');
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14)')->get($pass->pageUrl())
            ->assertOk()
            ->assertSee('https://files.example.com/vip.apk', false)
            ->assertDontSee('Mbunie-VIP-Setup', false);
    }
}
