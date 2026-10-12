<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app sends its device id in X-Mvpn-Device on every call. A device an
 * admin removed gets 403 device_revoked — it can't sign back in, fetch a
 * subscription or download a VPN config, even with a fresh code.
 */
class RejectRevokedDevice
{
    public const HEADER = 'X-Mvpn-Device';

    /** The install's older id; a removed phone can't escape by switching ids. */
    public const PREVIOUS_HEADER = 'X-Mvpn-Device-Prev';

    public function handle(Request $request, Closure $next): Response
    {
        $ids = array_values(array_filter([
            (string) $request->header(self::HEADER, ''),
            (string) $request->header(self::PREVIOUS_HEADER, ''),
        ]));

        if ($ids !== [] && Device::whereIn('fingerprint', $ids)->whereNotNull('revoked_at')->exists()) {
            return response()->json([
                'error' => 'device_revoked',
                'message' => 'This device was removed from the account. Contact support@mbuniehub.com.',
            ], 403);
        }

        return $next($request);
    }
}
