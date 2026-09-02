<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Passwordless auth: identifier (phone or email) + OTP. No admin action
 * needed to create an account (FR-NEW-01).
 */
class AuthController extends Controller
{
    public function requestOtp(Request $request, OtpDelivery $delivery): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:120'],
        ]);

        $identifier = trim($data['identifier']);
        $channel = str_contains($identifier, '@') ? 'email' : 'sms';

        if ($channel === 'email' && ! filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['identifier' => 'Invalid email address.']);
        }
        if ($channel === 'sms' && ! preg_match('/^\+?[1-9]\d{6,14}$/', $identifier)) {
            throw ValidationException::withMessages(['identifier' => 'Enter a valid phone number with country code, e.g. +8613800000000.']);
        }

        $key = 'otp:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'identifier' => 'Too many requests. Try again shortly.',
            ]);
        }
        RateLimiter::hit($key, 3600);

        [$otp, $code] = OtpCode::issue($identifier, $channel, $request->ip());

        try {
            $delivery->send($identifier, $channel, $code);
        } catch (\Throwable $e) {
            Log::error('OTP delivery failed for ' . $identifier . ': ' . $e->getMessage());
            throw ValidationException::withMessages([
                'identifier' => 'Could not send the code right now. Try again shortly.',
            ]);
        }

        return response()->json([
            'channel' => $channel,
            'expires_in' => 600,
            'debug_code' => app()->environment('local') ? $code : null,
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'size:6'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);

        $identifier = trim($data['identifier']);
        $channel = str_contains($identifier, '@') ? 'email' : 'sms';

        if (! OtpCode::verify($identifier, $channel, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Invalid or expired code.']);
        }

        $lookup = $channel === 'email' ? ['email' => $identifier] : ['phone' => $identifier];
        $user = User::firstOrCreate($lookup, ['signup_ip' => $request->ip()]);

        $user->forceFill([
            ($channel === 'email' ? 'email_verified_at' : 'phone_verified_at') => now(),
        ])->save();

        $token = $user->createToken($data['device_name'] ?? 'mvpn-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'identifier' => $identifier,
                'preferred_currency' => $user->preferred_currency,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $sub = $user->activeSubscription();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'phone' => $user->phone,
                'preferred_currency' => $user->preferred_currency,
            ],
            'subscription' => $sub ? [
                'status' => $sub->status,
                'plan' => $sub->plan_code,
                'expires_at' => $sub->expires_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['status' => 'ok']);
    }
}
