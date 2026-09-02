<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
        $this->seed(\Database\Seeders\SettingsSeeder::class);
    }

    #[DataProvider('adminPages')]
    public function test_admin_page_loads(string $path): void
    {
        $admin = User::where('is_admin', true)->first();

        $this->actingAs($admin)->get($path)->assertSuccessful();
    }

    public static function adminPages(): array
    {
        return [
            ['/admin'],
            ['/admin/settings'],
            ['/admin/payment-methods'],
            ['/admin/invoices'],
            ['/admin/nodes'],
            ['/admin/subscriptions'],
            ['/admin/users'],
            ['/admin/alerts'],
        ];
    }
}
