<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Node;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\User;
use App\Services\ManualPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
        Node::create([
            'name' => 'Tokyo 1', 'region' => 'tk', 'public_host' => 'n1.mbuniehub.com',
            'api_base' => 'https://x', 'api_secret' => Str::random(40),
            'reality_pubkey' => 'PBK', 'reality_short_id' => 'sid',
            'reality_sni' => 'www.apple.com', 'hysteria_port_range' => '20000-30000',
            'hysteria_cert_sha256' => 'AA', 'status' => 'online',
        ]);
    }

    public function test_full_manual_flow_ends_in_active_subscription(): void
    {
        Storage::fake('local');
        PaymentMethod::create(['type' => 'alipay', 'label' => 'Alipay ¥', 'currency' => 'cny', 'is_active' => true]);

        $user = User::factory()->create(['email' => 'buyer@example.com']);
        Sanctum::actingAs($user);

        $this->getJson('/api/payment-methods')
            ->assertOk()->assertJsonCount(1, 'methods');

        $create = $this->postJson('/api/checkout/manual', [
            'plan' => 'm1', 'currency' => 'cny',
        ])->assertOk();

        $invoiceId = $create->json('invoice_id');

        $this->postJson("/api/invoices/{$invoiceId}/proof", [
            'proof' => UploadedFile::fake()->image('receipt.jpg'),
            'note' => 'nimelipa 28',
        ])->assertOk()->assertJsonPath('status', 'submitted');

        $this->getJson('/api/subscription')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('pending_invoice.status', 'pending_review')
            ->assertJsonPath('pending_invoice.proof_uploaded', true);

        // admin approves
        app(ManualPaymentService::class)->approve(Invoice::find($invoiceId), 'admin:1');

        $this->getJson('/api/subscription')
            ->assertJsonPath('status', 'active')
            ->assertJsonStructure(['sub_url']);

        $this->assertSame(2, $user->fresh()->activeSubscription()->peers()->where('status', 'active')->count());
    }
}
