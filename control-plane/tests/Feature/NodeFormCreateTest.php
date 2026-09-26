<?php

namespace Tests\Feature;

use App\Filament\Resources\Nodes\Pages\CreateNode;
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
    }
}
