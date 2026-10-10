<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-to-server calls from MbunieEduHub. Each request carries
 *
 *   X-Mvpn-Timestamp: <unix seconds>
 *   X-Mvpn-Signature: hex(hmac_sha256(secret, "{ts}\n{METHOD}\n{path?query}\n{sha256(body)}"))
 *
 * signed with EDUHUB_PARTNER_SECRET. Requests older than 5 minutes are
 * rejected (replay window); state-changing calls are also idempotent by
 * reference, so a replay inside the window can't do anything twice.
 */
class VerifyPartnerSignature
{
    public const WINDOW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.eduhub.partner_secret');
        if (strlen($secret) < 32) {
            // Not configured: the partner API stays closed rather than open.
            return response()->json(['error' => 'partner_api_disabled'], 503);
        }

        $ts = $request->header('X-Mvpn-Timestamp');
        $sig = (string) $request->header('X-Mvpn-Signature');

        if (! ctype_digit((string) $ts) || abs(time() - (int) $ts) > self::WINDOW_SECONDS) {
            return response()->json(['error' => 'stale_or_missing_timestamp'], 401);
        }

        $expected = self::sign($secret, (int) $ts, $request->getMethod(), $request->getRequestUri(), $request->getContent());

        if (! hash_equals($expected, $sig)) {
            return response()->json(['error' => 'bad_signature'], 401);
        }

        return $next($request);
    }

    public static function sign(string $secret, int $ts, string $method, string $uri, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [$ts, strtoupper($method), $uri, hash('sha256', $body)]), $secret);
    }
}
