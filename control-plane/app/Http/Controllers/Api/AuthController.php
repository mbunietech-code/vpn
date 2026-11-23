<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Http\Middleware\RejectRevokedDevice;
use App\Models\User;
use App\Services\FreeTrial;
use App\Services\OtpDelivery;
use App\Services\VipClaimException;
use App\Services\VipPasses;
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

        // Per-identifier: 4 codes / 15 min. Per-IP: generous, since users in
        // China share NAT addresses heavily.
        foreach ([
            ['otp:id:' . sha1($identifier), 4, 900],
            ['otp:ip:' . $request->ip(), 40, 3600],
        ] as [$rlKey, $max, $decay]) {
            if (RateLimiter::tooManyAttempts($rlKey, $max)) {
                throw ValidationException::withMessages([
                    'identifier' => 'Too many code requests. Please wait a moment and try again.',
                ]);
            }
            RateLimiter::hit($rlKey, $decay);
        }

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

    public function verifyOtp(Request $request, FreeTrial $trial): JsonResponse
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

        // A suspended customer can't sign back in (admin "Suspend customer").
        if (($user->refresh()->status ?? 'active') !== 'active') {
            return response()->json([
                'error' => 'account_suspended',
                'message' => 'This account is suspended. Contact support@mbuniehub.com.',
            ], 403);
        }

        // New customer: 2 free days, once per account and once per phone.
        $trial->grantIfEligible($user, [
            (string) $request->header(RejectRevokedDevice::HEADER, ''),
            (string) $request->header(RejectRevokedDevice::PREVIOUS_HEADER, ''),
        ]);

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

    /**
     * VIP event pass: the code from the pass page signs this device in, no
     * account and no OTP. One code works on as many devices as were paid for.
     */
    public function vip(Request $request, VipPasses $passes): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'device_name' => ['nullable', 'string', 'max:80'],
            'platform' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            ['user' => $user, 'pass' => $pass] = $passes->claim($data['code'], [
                (string) $request->header(RejectRevokedDevice::HEADER, ''),
                (string) $request->header(RejectRevokedDevice::PREVIOUS_HEADER, ''),
            ], $data['platform'] ?? null, $data['device_name'] ?? null);
        } catch (VipClaimException $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        }

        $token = $user->createToken($data['device_name'] ?? 'mvpn-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'identifier' => 'VIP · '.$pass->event->name,
                'preferred_currency' => $user->preferred_currency,
            ],
            'vip' => [
                'event' => $pass->event->name,
                'devices' => $pass->devices,
                'ends_at' => $pass->event->ends_at?->toIso8601String(),
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
                'expires_at' => $sub->expiresAtForApp(),
            ] : null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['status' => 'ok']);
    }
}
