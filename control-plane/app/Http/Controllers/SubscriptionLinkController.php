<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Services\SubscriptionBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /sub/{token} - opaque, unauthenticated (the token IS the credential).
 * Returns the base64 share-link bundle that Hiddify / sing-box import.
 */
class SubscriptionLinkController extends Controller
{
    public function __invoke(Request $request, string $token, SubscriptionBuilder $builder): Response
    {
        $sub = Subscription::where('sub_token', $token)->first();

        abort_unless($sub && $sub->isActive(), 404);

        $sub->update(['last_synced_at' => now()]);

        $body = $builder->build($sub);

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Profile-Update-Interval' => '12',
            'Subscription-Userinfo' => sprintf(
                'upload=0; download=%d; total=%d; expire=%d',
                $sub->data_used_mb * 1048576,
                ($sub->plan()->data_cap_mb ?? 0) * 1048576,
                $sub->expires_at?->timestamp ?? 0,
            ),
        ]);
    }
}
