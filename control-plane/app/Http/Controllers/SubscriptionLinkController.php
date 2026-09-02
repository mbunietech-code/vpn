<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Services\SubscriptionBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /sub/{token} - opaque, unauthenticated (the token IS the credential).
 *
 *   (default)          → base64 share-link bundle (Hiddify / v2rayN / sing-box import)
 *   ?format=singbox    → full sing-box client config JSON (MVPN desktop engine)
 *       &platform=windows|linux|macos
 */
class SubscriptionLinkController extends Controller
{
    public function __invoke(Request $request, string $token, SubscriptionBuilder $builder): Response
    {
        $sub = Subscription::where('sub_token', $token)->first();

        abort_unless($sub && $sub->isActive(), 404);

        $sub->update(['last_synced_at' => now()]);

        $userinfo = sprintf(
            'upload=0; download=%d; total=%d; expire=%d',
            $sub->data_used_mb * 1048576,
            ($sub->plan()->data_cap_mb ?? 0) * 1048576,
            $sub->expires_at?->timestamp ?? 0,
        );

        if ($request->query('format') === 'singbox') {
            $platform = in_array($request->query('platform'), ['windows', 'linux', 'macos'], true)
                ? $request->query('platform') : 'windows';
            $protocol = in_array($request->query('protocol'), ['reality', 'hysteria2', 'auto'], true)
                ? $request->query('protocol') : 'auto';

            return response()->json($builder->buildSingbox($sub, $platform, $protocol), 200, [
                'Profile-Update-Interval' => '12',
                'Subscription-Userinfo' => $userinfo,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return response($builder->build($sub), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Profile-Update-Interval' => '12',
            'Subscription-Userinfo' => $userinfo,
        ]);
    }
}
