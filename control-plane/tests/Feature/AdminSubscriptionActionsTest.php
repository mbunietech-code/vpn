<?php

namespace Tests\Feature;

use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Node;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AdminSubscriptionActionsTest extends TestCase
{
    use RefreshDatabase;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
        $this->node = Node::create([
            'name' => 'KL1', 'region' => 'my', 'public_host' => 'n1.example.com',
            'api_secret' => Str::random(40), 'status' => 'online',
            'reality_pubkey' => 'PK', 'reality_short_id' => 'ab',
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function customer(): User
    {
        return User::factory()->create(['email' => 'cust@example.com', 'phone' => null]);
    }

    public function test_grant_plan_provisions_peers_and_is_audited(): void
    {
        $cust = $this->customer();

        Livewire::test(ListSubscriptions::class)
            ->callAction('grant', ['user_id' => $cust->id, 'plan_code' => 'm1', 'note' => 'gift'])
            ->assertHasNoActionErrors();

        $sub = $cust->activeSubscription();
        $this->assertNotNull($sub);
        $this->assertSame(2, $sub->peers()->where('status', 'active')->count());
        $this->assertTrue(AuditLog::where('action', 'subscription.granted')->exists());
        $this->assertSame(0, (int) $cust->invoices()->first()->amount_cents);
    }

    public function test_add_days_suspend_and_restore(): void
    {
        $cust = $this->customer();
        $sub = app(\App\Services\SubscriptionAdmin::class)->grant($cust, Plan::where('code', 'm1')->first(), 'test');
        $before = $sub->expires_at;

        Livewire::test(ListSubscriptions::class)
            ->callTableAction('extend', $sub, ['days' => 10])
            ->assertHasNoTableActionErrors();
        $this->assertEquals($before->copy()->addDays(10)->toDateTimeString(), $sub->fresh()->expires_at->toDateTimeString());

        $version = $this->node->fresh()->peer_version;
        Livewire::test(ListSubscriptions::class)->callTableAction('suspend', $sub, ['reason' => 'abuse']);
        $sub->refresh();
        $this->assertSame('suspended', $sub->status);
        $this->assertSame(0, $sub->peers()->where('status', 'active')->count());
        $this->assertGreaterThan($version, $this->node->fresh()->peer_version, 'node must re-sync');

        Livewire::test(ListSubscriptions::class)->callTableAction('restore', $sub);
        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertSame(2, $sub->peers()->where('status', 'active')->count());
    }

    public function test_suspend_customer_cuts_access_and_blocks_login(): void
    {
        $cust = $this->customer();
        $sub = app(\App\Services\SubscriptionAdmin::class)->grant($cust, Plan::where('code', 'm1')->first(), 'test');
        $cust->createToken('app');

        Livewire::test(ListUsers::class)->callTableAction('suspend', $cust, ['reason' => 'test']);

        $this->assertSame('suspended', $cust->fresh()->status);
        $this->assertSame('suspended', $sub->fresh()->status);
        $this->assertSame(0, $cust->tokens()->count(), 'app sessions revoked');

        Livewire::test(ListUsers::class)->callTableAction('restore', $cust->fresh());
        $this->assertSame('active', $cust->fresh()->status);
        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_editing_a_customer_keeps_password_and_status(): void
    {
        $cust = User::factory()->create(['password' => 'secret-pass-123', 'status' => 'active']);
        $hash = $cust->password;

        Livewire::test(\App\Filament\Resources\Users\Pages\EditUser::class, ['record' => $cust->getRouteKey()])
            ->fillForm(['name' => 'Renamed', 'password' => '', 'status' => 'suspended'])
            ->call('save')
            ->assertHasNoFormErrors();

        $cust->refresh();
        $this->assertSame('Renamed', $cust->name);
        $this->assertSame($hash, $cust->password, 'empty password field must not overwrite it');
        $this->assertSame('active', $cust->status, 'status changes only through Suspend/Restore');
    }

    public function test_invoice_amount_cannot_be_edited(): void
    {
        $cust = $this->customer();
        app(\App\Services\SubscriptionAdmin::class)->grant($cust, Plan::where('code', 'm1')->first(), 'test');
        $invoice = $cust->invoices()->firstOrFail();

        Livewire::test(\App\Filament\Resources\Invoices\Pages\EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->fillForm(['amount_cents' => 999999, 'status' => 'refunded'])
            ->call('save');

        $invoice->refresh();
        $this->assertSame(0, (int) $invoice->amount_cents);
        $this->assertSame('paid', $invoice->status);
    }

    public function test_approve_manual_payment_from_the_invoices_menu(): void
    {
        $cust = $this->customer();
        $invoice = \App\Models\Invoice::create([
            'user_id' => $cust->id, 'plan_code' => 'm1', 'provider' => 'manual',
            'currency' => 'tzs', 'amount_cents' => 2000000, 'status' => 'pending_review',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        Livewire::test(\App\Filament\Resources\Invoices\Pages\ListInvoices::class)
            ->assertSee('TZS 20,000 · m1')
            ->callTableAction('approve', $invoice, ['note' => 'paid by M-Pesa'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($cust->activeSubscription());
    }

    public function test_raw_create_pages_are_gone(): void
    {
        $this->get('/admin/subscriptions/create')->assertNotFound();
        $this->get('/admin/peers/create')->assertNotFound();
        $this->get('/admin/devices/create')->assertNotFound();
        $this->get('/admin/invoices/create')->assertNotFound();
        $this->get('/admin/alerts/create')->assertNotFound();
    }
}
