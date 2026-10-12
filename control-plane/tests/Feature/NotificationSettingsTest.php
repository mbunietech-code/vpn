<?php

namespace Tests\Feature;

use App\Filament\Pages\Notifications;
use App\Mail\AlertTestMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\PaymentAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.brevo.key' => null]);
        $this->actingAs(User::factory()->create(['is_admin' => true, 'email' => 'admin@example.com']));
    }

    public function test_page_opens_and_saves_the_alert_emails(): void
    {
        $this->get('/admin/notifications')->assertOk()->assertSee('Notification emails');

        Livewire::test(Notifications::class)
            ->fillForm(['emails' => ['Support@mt.co.tz', 'boss@example.com', 'boss@example.com']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('support@mt.co.tz, boss@example.com', Setting::value('payment_alert_emails'));
        $this->assertSame(['support@mt.co.tz', 'boss@example.com'], app(PaymentAlerts::class)->recipients());
    }

    public function test_bad_addresses_are_refused(): void
    {
        Livewire::test(Notifications::class)
            ->fillForm(['emails' => ['not-an-email']])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertNull(Setting::value('payment_alert_emails'));
    }

    public function test_empty_list_falls_back_to_admins_and_test_email_goes_out(): void
    {
        Mail::fake();

        Livewire::test(Notifications::class)
            ->fillForm(['emails' => []])
            ->call('save')
            ->callAction('test')
            ->assertHasNoActionErrors();

        Mail::assertSent(AlertTestMail::class, fn ($m) => $m->hasTo('admin@example.com'));
    }
}
