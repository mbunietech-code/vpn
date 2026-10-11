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

    public function handle(Request $request, Closure $next): Response
    {
        $fingerprint = (string) $request->header(self::HEADER, '');

        if ($fingerprint !== '' && Device::where('fingerprint', $fingerprint)->whereNotNull('revoked_at')->exists()) {
            return response()->json([
                'error' => 'device_revoked',
                'message' => 'This device was removed from the account. Contact support@mbuniehub.com.',
            ], 403);
        }

        return $next($request);
    }
}
