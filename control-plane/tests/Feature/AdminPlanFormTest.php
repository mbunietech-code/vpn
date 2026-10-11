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
