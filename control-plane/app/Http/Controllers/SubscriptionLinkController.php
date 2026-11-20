<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RejectRevokedDevice;
use App\Models\Device;
use App\Models\Subscription;
use App\Services\SubscriptionBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /sub/{token} - opaque, unauthenticated (the token IS the credential).
 *
 *   (default)          → base64 share-link bundle (Hiddify / v2rayN / sing-box import)
 *   ?format=singbox    → full sing-box client config JSON (MVPN desktop engine)
 *       &platform=windows|linux|macos|android|ios
 *   ?format=ovpn       → this subscription's OpenVPN profile (OpenVPN Connect)
 */
class SubscriptionLinkController extends Controller
{
    public function __invoke(Request $request, string $token, SubscriptionBuilder $builder): Response
    {
        $sub = Subscription::where('sub_token', $token)->first();

        abort_unless($sub && $sub->isActive(), 404);

        $sub->update(['last_synced_at' => now()]);

        $this->trackSingboxApp($request, $sub);

        $userinfo = sprintf(
            'upload=0; download=%d; total=%d; expire=%d',
            $sub->data_used_mb * 1048576,
            ($sub->plan()->data_cap_mb ?? 0) * 1048576,
            $sub->expires_at?->timestamp ?? 0,
        );

        if ($request->query('format') === 'singbox') {
            $platform = in_array($request->query('platform'), ['windows', 'linux', 'macos', 'android', 'ios'], true)
                ? $request->query('platform') : 'windows';
            $protocol = in_array($request->query('protocol'), ['reality', 'hysteria2', 'openvpn', 'auto'], true)
                ? $request->query('protocol') : 'auto';

            return response()->json($builder->buildSingbox($sub, $platform, $protocol), 200, [
                'Profile-Update-Interval' => '12',
                'Subscription-Userinfo' => $userinfo,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        // A plain .ovpn for the official OpenVPN Connect app.
        if ($request->query('format') === 'ovpn') {
            $first = $builder->openVpnProfiles($sub)[0] ?? null;
            abort_unless($first, 404);

            return response($first['profile'], 200, [
                'Content-Type' => 'application/x-openvpn-profile',
                'Content-Disposition' => 'attachment; filename="mbunie-vpn.ovpn"',
                'Cache-Control' => 'no-store',
            ]);
        }

        return response($builder->build($sub), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Profile-Update-Interval' => '12',
            'Subscription-Userinfo' => $userinfo,
        ]);
    }

    /** sing-box apps' User-Agent name → platform (SFI is the iPhone/iPad app). */
    private const SINGBOX_APPS = [
        'SFI' => ['ios', 'iPhone'],
        'SFM' => ['macos', 'Mac'],
        'SFA' => ['android', 'Android'],
        'SFT' => ['tvos', 'Apple TV'],
    ];

    /**
     * The official sing-box app (the iPhone route until our own iPhone app)
     * only fetches this link, so it never registers like Mbunie VPN does.
     * List it under Admin › Devices, one row per subscription and app, seen
     * at each profile update (every ~12 h, or when the customer refreshes).
     * It cannot report whether its VPN is on. A removed one gets no updates.
     */
    private function trackSingboxApp(Request $request, Subscription $sub): void
    {
        // Mbunie VPN registers itself (and RejectRevokedDevice checks it).
        if ($request->hasHeader(RejectRevokedDevice::HEADER)) {
            return;
        }
        // e.g. "SFI (sing-box 1.14.8; language en_US)"
        if (! preg_match('/^(SF[IMAT])\b.*?sing-box ([0-9][0-9A-Za-z.\-]*)/', (string) $request->userAgent(), $m)) {
            return;
        }
        [$platform, $model] = self::SINGBOX_APPS[$m[1]];

        try {
            $device = Device::firstOrNew([
                'subscription_id' => $sub->id,
                'fingerprint' => 'singbox-'.strtolower($m[1]),
            ]);
        } catch (\Throwable $e) {
            report($e); // never fail the profile download over this

            return;
        }
        abort_if($device->exists && $device->revoked_at !== null, 403, 'device_revoked');

        try {
            $device->fill([
                'platform' => $platform,
                'name' => $device->name ?? $model,
                'model' => "{$model} (sing-box app)",
                'app_version' => 'sing-box '.substr($m[2], 0, 23),
                'last_seen_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            report($e); // never fail the profile download over this
        }
    }
}
