<?php

namespace Tests\Feature;

use App\Mail\ExpiryReminderMail;
use App\Mail\PaymentProofMail;
use App\Models\Invoice;
use App\Models\User;
use App\Services\PaymentAlerts;
use Database\Seeders\MvpnSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MvpnSeeder::class);
        config(['services.brevo.key' => null]);
        Mail::fake();
    }

    private function subscriber(string $email = 'c@example.com', ?\DateTimeInterface $expires = null): User
    {
        $u = User::factory()->create(['email' => $email]);
        $u->subscriptions()->create([
            'plan_code' => 'm1', 'status' => 'active', 'max_devices' => 2,
            'started_at' => now()->subDays(27), 'expires_at' => $expires ?? now()->addDays(30),
        ]);

        return $u;
    }

    public function test_admins_get_an_email_when_a_payment_screenshot_arrives(): void
    {
        Storage::fake('local');
        User::factory()->create(['is_admin' => true, 'email' => 'boss@mbuniehub.com']);
        $u = User::factory()->create(['email' => 'buyer@example.com']);
        $invoice = Invoice::create([
            'user_id' => $u->id, 'plan_code' => 'm1', 'provider' => 'manual', 'payment_method' => 'alipay',
            'currency' => 'cny', 'amount_cents' => 5500, 'status' => 'pending', 'idempotency_key' => Str::uuid(),
        ]);

        $this->actingAs($u, 'sanctum')
            ->post("/api/invoices/{$invoice->id}/proof", ['proof' => UploadedFile::fake()->image('pay.jpg'), 'note' => 'paid'], ['Accept' => 'application/json'])
            ->assertOk();

        Mail::assertSent(PaymentProofMail::class, fn (PaymentProofMail $m) => $m->hasTo('boss@mbuniehub.com')
            && str_contains($m->envelope()->subject, 'CNY 55.00'));
    }

    public function test_configured_alert_emails_replace_the_admin_list(): void
    {
        User::factory()->create(['is_admin' => true, 'email' => 'boss@mbuniehub.com']);
        config(['services.mvpn.payment_alert_emails' => 'support@mt.co.tz, bad-address']);

        $this->assertSame(['support@mt.co.tz'], app(PaymentAlerts::class)->recipients());
    }

    public function test_reminders_go_out_once_at_3_days_and_once_at_1_day(): void
    {
        $u = $this->subscriber(expires: now()->addDays(2)->addHours(12));

        $this->artisan('mvpn:remind-expiring')->assertSuccessful();
        $this->artisan('mvpn:remind-expiring')->assertSuccessful();
        Mail::assertSent(ExpiryReminderMail::class, 1);

        $this->travel(2)->days();
        $this->artisan('mvpn:remind-expiring')->assertSuccessful();
        Mail::assertSent(ExpiryReminderMail::class, 2);
        Mail::assertSent(ExpiryReminderMail::class, fn ($m) => $m->hasTo('c@example.com'));
    }

    public function test_renewing_arms_the_reminders_again_and_far_plans_are_left_alone(): void
    {
        $u = $this->subscriber(expires: now()->addDays(2));
        $this->subscriber('far@example.com', now()->addDays(20));

        $this->artisan('mvpn:remind-expiring');
        Mail::assertSent(ExpiryReminderMail::class, 1);

        // Renewed: new end date, then it nears again.
        $sub = $u->subscriptions()->first();
        $sub->update(['expires_at' => now()->addDays(32)]);
        $this->travel(30)->days();
        $this->artisan('mvpn:remind-expiring');
        Mail::assertSent(ExpiryReminderMail::class, 2);
    }

    public function test_app_version_endpoint(): void
    {
        config(['services.mvpn.app_version' => '1.0.13', 'services.mvpn.min_app_version' => '1.0.8']);

        $this->getJson('/api/app/version')->assertOk()
            ->assertJsonPath('latest', '1.0.13')
            ->assertJsonPath('min_supported', '1.0.8')
            ->assertJsonStructure(['android', 'windows', 'page']);
    }

    public function test_android_devices_see_the_android_version(): void
    {
        config(['services.mvpn.app_version' => '1.0.13', 'services.mvpn.android_version' => '1.0.14', 'services.mvpn.windows_version' => null]);
        $sub = User::factory()->create()->subscriptions()->create(['plan_code' => 'm1', 'sub_token' => 'tok-version']);
        $sub->devices()->create(['fingerprint' => 'phone-1', 'platform' => 'android']);
        $sub->devices()->create(['fingerprint' => 'pc-1', 'platform' => 'windows']);

        $this->getJson('/api/app/version', ['X-Mvpn-Device' => 'phone-1'])->assertJsonPath('latest', '1.0.14');
        $this->getJson('/api/app/version', ['X-Mvpn-Device' => 'pc-1'])->assertJsonPath('latest', '1.0.13');
        $sub->devices()->create(['fingerprint' => 'mac-1', 'platform' => 'macos']);
        config(['services.mvpn.windows_version' => '1.0.15']);
        $this->getJson('/api/app/version', ['X-Mvpn-Device' => 'pc-1'])->assertJsonPath('latest', '1.0.15');
        $this->getJson('/api/app/version', ['X-Mvpn-Device' => 'mac-1'])->assertJsonPath('latest', '1.0.13');
        $this->getJson('/api/app/version')->assertJsonPath('latest', '1.0.13');
        $this->get('/download')->assertOk()->assertSee('Version 1.0.14');
    }
}
