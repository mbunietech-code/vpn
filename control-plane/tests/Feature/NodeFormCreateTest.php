<?php

namespace Tests\Feature;

use App\Filament\Resources\Nodes\Pages\CreateNode;
use App\Filament\Resources\Nodes\Pages\EditNode;
use App\Models\Node;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NodeFormCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_node_from_the_form(): void
    {
        $this->seed(\Database\Seeders\MvpnSeeder::class);
        $admin = User::where('email', 'admin@mbunievpn.com')->first() ?? User::factory()->create(['email' => 'admin@mbunievpn.com']);
        $this->actingAs($admin);

        Livewire::test(CreateNode::class)
            ->fillForm([
                'name' => 'Kuala Lumpur 1',
                'region' => 'my',
                'public_host' => 'n1.mbuniehub.com',
                'api_secret' => 'tok123',
                'status' => 'provisioning',
                'capacity' => 500,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('tok123', Node::first()->api_secret);

        // Saving the edit form with the (empty) token field keeps the token.
        Livewire::test(EditNode::class, ['record' => Node::first()->getRouteKey()])
            ->fillForm(['capacity' => 800])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('tok123', Node::first()->api_secret);
        $this->assertSame(800, (int) Node::first()->capacity);
    }

    public function test_edit_pages_work_for_otp_users_without_a_name(): void
    {
        $this->seed(\Database\Seeders\MvpnSeeder::class);
        $this->actingAs(User::where('email', 'admin@mbunievpn.com')->first() ?? User::factory()->create(['email' => 'admin@mbunievpn.com']));

        $otpUser = User::create(['email' => 'otp@example.com']); // exactly what OTP signup creates
        $sub = $otpUser->subscriptions()->create([
            'plan_code' => 'm1', 'status' => 'active', 'max_devices' => 2,
            'started_at' => now(), 'expires_at' => now()->addDays(7),
        ]);
        $invoice = \App\Models\Invoice::create([
            'user_id' => $otpUser->id, 'plan_code' => 'm1', 'provider' => 'manual',
            'currency' => 'cny', 'amount_cents' => 700, 'status' => 'pending',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->get("/admin/subscriptions/{$sub->id}/edit")->assertOk()->assertSee('otp@example.com');
        $this->get("/admin/invoices/{$invoice->id}/edit")->assertOk();
    }
}
