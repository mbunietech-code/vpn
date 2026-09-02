<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_otp_is_sent_and_verifies_into_a_token(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/otp/request', ['identifier' => 'user@example.com'])
            ->assertOk()
            ->assertJsonPath('channel', 'email');

        Mail::assertSent(OtpMail::class);

        // Grab the real code (Mail::fake keeps the mailable; code lives on it)
        $sent = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$sent) {
            $sent = $m->code;
            return true;
        });

        $resp = $this->postJson('/api/auth/otp/verify', [
            'identifier' => 'user@example.com',
            'code' => $sent,
            'device_name' => 'test',
        ])->assertOk();

        $this->assertNotEmpty($resp->json('token'));
        $this->assertDatabaseHas('users', ['email' => 'user@example.com']);
    }

    public function test_bad_phone_is_rejected(): void
    {
        $this->postJson('/api/auth/otp/request', ['identifier' => '12345'])
            ->assertStatus(422);
    }

    public function test_wrong_code_is_rejected(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'x@example.com']);
        OtpCode::issue('x@example.com', 'email');

        $this->postJson('/api/auth/otp/verify', [
            'identifier' => 'x@example.com',
            'code' => '000000',
        ])->assertStatus(422);
    }
}
