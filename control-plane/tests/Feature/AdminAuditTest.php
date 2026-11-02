<?php

namespace Tests\Feature;

use App\Filament\Resources\Devices\Pages\EditDevice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Nodes\Pages\EditNode;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Settings\Pages\EditSetting;
use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\PaymentMethod;
use App\Models\Peer;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every admin page opens with realistic data, and the edit-page actions
 * behave like the list-page ones (VPN access cut on the nodes, audited).
 */
class AdminAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Node $node;

    private Subscription $sub;

    private Invoice $invoice;

    private Device $device;

    private Alert $alert;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
        $this->seed(\Database\Seeders\SettingsSeeder::class);

        $this->admin = User::where('is_admin', true)->first();
        $this->customer = User::create(['email' => 'mteja@example.com', 'phone' => '+255700000001', 'password' => 'x']);
        $this->node = Node::create([
            'name' => 'KL1', 'region' => 'my', 'public_host' => 'n1.example.com', 'api_secret' => Str::random(48),
            'status' => 'online', 'capacity' => 500, 'priority' => 10,
            'openvpn_config' => "client\nremote n1.example.com 1194\n<ca>CA</ca>",
            'openvpn_username' => 'ovpn-user', 'openvpn_password' => 'ovpn-pass',
            'last_health_at' => now(), 'health' => ['applied_version' => 1],
        ]);
        $this->invoice = Invoice::create([
            'user_id' => $this->customer->id, 'plan_code' => 'm3', 'provider' => 'manual', 'currency' => 'cny',
            'amount_cents' => 6000, 'status' => 'paid', 'paid_at' => now(), 'idempotency_key' => 'audit-1',
            'meta' => ['user_note' => 'Alipay', 'proof_uploaded_at' => now()->toIso8601String()],
        ]);
        $this->sub = app(ProvisioningService::class)->fulfill($this->invoice);
        $this->device = Device::create([
            'subscription_id' => $this->sub->id, 'fingerprint' => 'fp-1', 'platform' => 'android',
            'name' => 'Infinix', 'last_seen_at' => now(),
        ]);
        $this->alert = Alert::create(['severity' => 'warn', 'source' => 'node.health', 'title' => 'Slow', 'context' => ['a' => 1], 'node_id' => $this->node->id]);
        $this->method = PaymentMethod::create(['type' => 'alipay', 'label' => 'Alipay', 'currency' => 'cny', 'is_active' => true]);
    }

    public function test_every_admin_page_opens(): void
    {
        $peer = Peer::where('subscription_id', $this->sub->id)->first();
        $plan = Plan::where('code', 'm3')->first();
        $setting = Setting::first();

        $pages = [
            '/admin', '/admin/notifications',
            '/admin/users', '/admin/users/create', "/admin/users/{$this->customer->id}/edit",
            '/admin/subscriptions', "/admin/subscriptions/{$this->sub->id}/edit",
            '/admin/invoices', "/admin/invoices/{$this->invoice->id}/edit",
            '/admin/plans', '/admin/plans/create', "/admin/plans/{$plan->id}/edit",
            '/admin/nodes', '/admin/nodes/create', "/admin/nodes/{$this->node->id}/edit",
            '/admin/peers', "/admin/peers/{$peer->id}/edit",
            '/admin/devices', "/admin/devices/{$this->device->id}/edit",
            '/admin/alerts', "/admin/alerts/{$this->alert->id}/edit",
            '/admin/payment-methods', '/admin/payment-methods/create', "/admin/payment-methods/{$this->method->id}/edit",
            '/admin/settings', "/admin/settings/{$setting->id}/edit",
        ];

        foreach ($pages as $path) {
            $this->actingAs($this->admin)->get($path)->assertSuccessful();
        }
    }

    public function test_a_node_switched_off_on_purpose_is_not_reported_down(): void
    {
        Node::create([
            'name' => 'Old', 'region' => 'hk', 'public_host' => 'old.example.com', 'api_secret' => Str::random(48),
            'status' => 'disabled', 'capacity' => 500, 'priority' => 100,
        ]);

        $this->actingAs($this->admin)->get('/admin')
            ->assertSuccessful()
            ->assertSee('1 / 1')
            ->assertDontSee('A node is down!');
    }

    public function test_a_suspended_admin_is_locked_out(): void
    {
        $other = User::create(['email' => 'admin2@example.com', 'password' => 'a-long-password-1']);
        $other->forceFill(['is_admin' => true, 'status' => 'suspended'])->save();

        $this->actingAs($other)->get('/admin')->assertForbidden();
    }

    public function test_the_admin_password_command_creates_a_working_admin(): void
    {
        $this->artisan('mvpn:admin-password', ['--email' => 'owner@example.com', '--password' => 'a-long-password-1'])
            ->assertSuccessful();

        $owner = User::where('email', 'owner@example.com')->first();
        $this->assertTrue($owner->is_admin);
        $this->actingAs($owner)->get('/admin')->assertSuccessful();
    }

    public function test_customers_cannot_open_the_admin_panel(): void
    {
        $this->actingAs($this->customer)->get('/admin')->assertForbidden();
        $this->actingAs($this->customer)->get('/admin/users')->assertForbidden();
    }

    public function test_saving_a_node_keeps_its_openvpn_fallback_and_secrets(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(EditNode::class, ['record' => $this->node->getRouteKey()])
            ->assertDontSee('ovpn-pass')
            ->assertDontSee('<ca>CA</ca>')
            ->fillForm(['name' => 'Kuala Lumpur 1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $node = $this->node->fresh();
        $this->assertSame('Kuala Lumpur 1', $node->name);
        $this->assertStringContainsString('<ca>CA</ca>', (string) $node->openvpn_config);
        $this->assertSame('ovpn-user', $node->openvpn_username);
        $this->assertSame('ovpn-pass', $node->openvpn_password);
    }

    public function test_deleting_a_subscription_from_its_page_cuts_vpn_access(): void
    {
        $version = $this->node->fresh()->peer_version;
        $this->actingAs($this->admin);

        Livewire::test(EditSubscription::class, ['record' => $this->sub->getRouteKey()])
            ->callAction('delete');

        $this->assertNull(Subscription::find($this->sub->id));
        $this->assertSame(0, Peer::where('subscription_id', $this->sub->id)->count());
        $this->assertGreaterThan($version, $this->node->fresh()->peer_version);
        $this->assertTrue(AuditLog::where('action', 'subscription.deleted')->exists());
    }

    public function test_deleting_a_payment_from_its_page_is_audited(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(EditInvoice::class, ['record' => $this->invoice->getRouteKey()])
            ->callAction('delete');

        $this->assertNull(Invoice::find($this->invoice->id));
        $this->assertTrue(AuditLog::where('action', 'invoice.deleted')->exists());
    }

    public function test_deleting_a_device_from_its_page_goes_through_the_device_service(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(EditDevice::class, ['record' => $this->device->getRouteKey()])
            ->callAction('delete');

        $this->assertNull(Device::find($this->device->id));
        $this->assertTrue(AuditLog::where('target', "device:{$this->device->id}")->exists());
    }

    public function test_a_plan_with_customers_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin);
        $plan = Plan::where('code', 'm3')->first();

        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->assertActionHidden('delete');

        $unused = Plan::where('code', 'trial7')->first();
        Livewire::test(EditPlan::class, ['record' => $unused->getRouteKey()])
            ->assertActionVisible('delete');
    }

    public function test_a_bought_plan_keeps_its_code(): void
    {
        $this->actingAs($this->admin);
        $plan = Plan::where('code', 'm3')->first();

        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->assertFormFieldDisabled('code')
            ->fillForm(['code' => 'm3x', 'days' => 92])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('m3', $plan->fresh()->code);
        $this->assertSame(92, (int) $plan->fresh()->days);
    }

    public function test_a_new_admin_must_get_a_password(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(\App\Filament\Resources\Users\Pages\EditUser::class, ['record' => $this->customer->getRouteKey()])
            ->fillForm(['is_admin' => true, 'password' => ''])
            ->call('save')
            ->assertHasFormErrors(['password' => 'required']);
        $this->assertFalse($this->customer->fresh()->is_admin);

        Livewire::test(\App\Filament\Resources\Users\Pages\EditUser::class, ['record' => $this->customer->getRouteKey()])
            ->fillForm(['is_admin' => true, 'password' => 'a-long-password-1'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($this->customer->fresh()->is_admin);

        // Later edits keep the password when the field is left empty.
        Livewire::test(\App\Filament\Resources\Users\Pages\EditUser::class, ['record' => $this->customer->getRouteKey()])
            ->fillForm(['name' => 'Mteja', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-long-password-1', $this->customer->fresh()->password));
    }

    public function test_secret_settings_never_reach_the_browser_and_blank_keeps_them(): void
    {
        $secret = Setting::where('is_secret', true)->first();
        $secret->update(['value' => 'sk_live_super_secret_value']);
        $this->actingAs($this->admin);

        Livewire::test(EditSetting::class, ['record' => $secret->getRouteKey()])
            ->assertDontSee('sk_live_super_secret_value')
            ->assertFormSet(['value' => null])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('sk_live_super_secret_value', $secret->fresh()->value);

        Livewire::test(EditSetting::class, ['record' => $secret->getRouteKey()])
            ->fillForm(['value' => 'sk_live_new'])
            ->call('save');
        $this->assertSame('sk_live_new', $secret->fresh()->value);

        Livewire::test(EditSetting::class, ['record' => $secret->getRouteKey()])
            ->fillForm(['clear' => true])
            ->call('save');
        $this->assertTrue(blank($secret->fresh()->value));
    }
}
