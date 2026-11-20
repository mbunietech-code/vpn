<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreeTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::create([
            'code' => 'm1', 'name' => 'Mwezi 1', 'days' => 30, 'max_devices' => 2,
            'price_usd_cents' => 399, 'price_cny_cents' => 2800, 'sort' => 1, 'is_active' => true,
        ]);
    }

    private function signIn(string $email, ?string $device = 'phone-A'): string
    {
        [, $code] = OtpCode::issue($email, 'email', '127.0.0.1');

        return $this->withHeaders(array_filter(['X-Mvpn-Device' => $device]))
            ->postJson('/api/auth/otp/verify', ['identifier' => $email, 'code' => $code])
            ->assertOk()
            ->json('token');
    }

    private function planCodes(?string $token = null): array
    {
        $req = $token ? $this->withToken($token) : $this;

        return collect($req->getJson('/api/plans')->assertOk()->json('plans'))->pluck('code')->all();
    }

    public function test_new_customer_gets_a_two_day_trial_on_first_sign_in(): void
    {
        $token = $this->signIn('new@example.com');

        $sub = User::where('email', 'new@example.com')->first()->activeSubscription();
        $this->assertNotNull($sub);
        $this->assertSame('trial', $sub->plan_code);
        $this->assertEqualsWithDelta(now()->addDays(2)->timestamp, $sub->expires_at->timestamp, 60);

        $this->withToken($token)->getJson('/api/subscription')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('plan', 'trial');

        // Running trial is visible to its owner.
        $this->assertContains('trial', $this->planCodes($token));
    }

    public function test_trial_is_given_only_once_per_account(): void
    {
        $this->signIn('again@example.com');
        $this->app['auth']->forgetGuards();
        $this->signIn('again@example.com');

        $this->assertSame(1, User::where('email', 'again@example.com')->first()->subscriptions()->count());
    }

    public function test_a_new_email_on_the_same_phone_gets_no_second_trial(): void
    {
        $this->signIn('first@example.com', 'phone-B');
        $this->app['auth']->forgetGuards();
        $token = $this->signIn('second@example.com', 'phone-B');

        $this->assertNull(User::where('email', 'second@example.com')->first()->activeSubscription());
        $this->assertNotContains('trial', $this->planCodes($token));
    }

    public function test_no_device_id_means_no_trial(): void
    {
        $this->signIn('nodevice@example.com', null);

        $this->assertNull(User::where('email', 'nodevice@example.com')->first()->activeSubscription());
    }

    public function test_trial_plan_disappears_once_it_ends_and_cannot_be_bought(): void
    {
        $token = $this->signIn('ended@example.com', 'phone-C');

        $this->travel(3)->days();
        $this->artisan('mvpn:sweep-expired')->assertSuccessful();
        $this->app['auth']->forgetGuards();

        $this->assertSame(['m1'], $this->planCodes($token));
        $this->assertNotContains('trial', $this->planCodes());   // signed out: never shown

        $this->withToken($token)
            ->postJson('/api/checkout/manual', ['plan' => 'trial', 'currency' => 'usd'])
            ->assertNotFound();
    }
}
