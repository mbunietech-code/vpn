<?php

namespace Tests\Feature;

use App\Filament\Resources\Settings\Pages\ManageSettings;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings & Keys: one page, a tab per area, one Save button. */
class ManageSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MvpnSeeder::class);
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $this->admin = User::where('is_admin', true)->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_the_page_shows_a_tab_per_area_with_its_sections(): void
    {
        $this->get('/admin/settings')
            ->assertSuccessful()
            ->assertSee('Email')
            ->assertSee('Payments')
            ->assertSee('SMS')
            ->assertSee('Alerts')
            ->assertSee('SMTP mailbox')
            ->assertSee('Login codes (OTP) are sent from here')
            ->assertSee('Save settings');
    }

    public function test_the_whole_email_setup_is_saved_at_once(): void
    {
        Livewire::test(ManageSettings::class)
            ->fillForm([
                'v_mail_host' => 'smtp.hostinger.com',
                'v_mail_port' => '465',
                'v_mail_username' => 'noreply@mbuniehub.com',
                'v_mail_password' => 'new-mailbox-password',
                'v_mail_from_address' => 'noreply@mbuniehub.com',
                'v_mail_from_name' => 'Mbunie VPN Login',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('smtp.hostinger.com', Setting::value('mail_host'));
        $this->assertSame('465', Setting::value('mail_port'));
        $this->assertSame('new-mailbox-password', Setting::value('mail_password'));
        $this->assertSame('Mbunie VPN Login', Setting::value('mail_from_name'));
        $this->assertSame(6, AuditLog::where('action', 'setting.updated')->count());
    }

    public function test_a_saved_secret_is_never_sent_back_and_an_empty_field_keeps_it(): void
    {
        Setting::where('key', 'mail_password')->firstOrFail()->update(['value' => 'keep-me']);

        Livewire::test(ManageSettings::class)
            ->assertFormSet(['v_mail_password' => null])
            ->assertDontSee('keep-me')
            ->fillForm(['v_mail_from_name' => 'Mbunie VPN'])
            ->call('save');

        $this->assertSame('keep-me', Setting::value('mail_password'));
    }

    public function test_values_from_the_server_env_are_shown_but_not_copied_on_save(): void
    {
        config([
            'mail.mailers.smtp.host' => 'smtp.hostinger.com',
            'mail.mailers.smtp.username' => 'noreply@mbuniehub.com',
            'mail.mailers.smtp.password' => 'env-secret',
        ]);

        Livewire::test(ManageSettings::class)
            ->assertFormSet([
                'v_mail_host' => 'smtp.hostinger.com',
                'v_mail_username' => 'noreply@mbuniehub.com',
                'v_mail_password' => null,
            ])
            ->assertDontSee('env-secret')
            ->assertSee('set in the server .env')
            ->call('save');

        // Untouched .env values stay in .env, not copied into the database.
        $this->assertNull(Setting::value('mail_host'));
        $this->assertNull(Setting::value('mail_username'));
        $this->assertNull(Setting::value('mail_password'));
    }

    public function test_a_saved_secret_can_be_removed(): void
    {
        Setting::where('key', 'brevo_api_key')->firstOrFail()->update(['value' => 'old-key']);

        Livewire::test(ManageSettings::class)
            ->fillForm(['clear_brevo_api_key' => true])
            ->call('save');

        $this->assertNull(Setting::value('brevo_api_key'));
    }

    public function test_emptying_a_plain_setting_goes_back_to_the_env_value(): void
    {
        Setting::where('key', 'mail_from_name')->firstOrFail()->update(['value' => 'Old name']);

        Livewire::test(ManageSettings::class)
            ->fillForm(['v_mail_from_name' => ''])
            ->call('save');

        $this->assertNull(Setting::value('mail_from_name'));
    }

    public function test_bad_email_and_port_are_refused(): void
    {
        Livewire::test(ManageSettings::class)
            ->fillForm(['v_mail_from_address' => 'not-an-email', 'v_mail_port' => 'abc'])
            ->call('save')
            ->assertHasFormErrors(['v_mail_from_address', 'v_mail_port']);

        $this->assertNull(Setting::value('mail_from_address'));
    }

    public function test_customers_cannot_open_settings(): void
    {
        $customer = User::create(['name' => 'Li', 'email' => 'li@example.com', 'password' => 'x']);

        $this->actingAs($customer)->get('/admin/settings')->assertForbidden();
    }
}
