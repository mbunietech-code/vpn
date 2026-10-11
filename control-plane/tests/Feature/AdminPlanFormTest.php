<?php

namespace Tests\Feature;

use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPlanFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_table_cells_show_real_values(): void
    {
        Plan::where('code', 'm3')->update(['price_usd_cents' => 999, 'price_tzs_cents' => 5000000]);

        // Filament injects closure args by name: fn ($state) — fn ($s) got null and showed $0.00.
        Livewire::test(\App\Filament\Resources\Plans\Pages\ListPlans::class)
            ->assertSee('$9.99')
            ->assertSee('TSh 50,000');

        $sub = User::factory()->create()->subscriptions()->create([
            'plan_code' => 'm1', 'status' => 'active', 'max_devices' => 2,
            'started_at' => now(), 'expires_at' => now()->addDays(5),
        ]);
        $sub->devices()->create(['fingerprint' => 'x', 'platform' => 'android', 'vpn_connected' => true, 'last_seen_at' => now()]);

        Livewire::test(\App\Filament\Resources\Devices\Pages\ListDevices::class)->assertSee('Online');
    }

    public function test_prices_are_typed_in_normal_amounts_and_stored_in_cents(): void
    {
        $plan = Plan::where('code', 'm1')->firstOrFail();
        $plan->update(['price_usd_cents' => 8]); // the $0.08 mistake

        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->assertSchemaStateSet(['price_usd_cents' => 0.08])
            ->fillForm([
                'price_usd_cents' => 8,
                'price_cny_cents' => 58,
                'price_tzs_cents' => 20000,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $plan->refresh();
        $this->assertSame(800, (int) $plan->price_usd_cents);
        $this->assertSame(5800, (int) $plan->price_cny_cents);
        $this->assertSame(2000000, (int) $plan->price_tzs_cents);

        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->assertSchemaStateSet(['price_usd_cents' => 8.0, 'price_tzs_cents' => 20000]);
    }
}
