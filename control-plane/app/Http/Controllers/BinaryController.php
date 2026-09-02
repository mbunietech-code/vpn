<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the platform sing-box binary to the MVPN desktop engine so clients
 * inside China don't have to reach GitHub. Upload the extracted binaries once:
 *
 *   storage/app/bin/sing-box-windows-amd64.exe
 *   storage/app/bin/sing-box-linux-amd64
 *   storage/app/bin/sing-box-darwin-arm64
 *   storage/app/bin/sing-box-darwin-amd64
 *
 * plus storage/app/bin/VERSION  (e.g. "1.11.15") for the client to compare.
 */
class BinaryController extends Controller
{
    private const MAP = [
        'windows-amd64' => 'sing-box-windows-amd64.exe',
        'linux-amd64' => 'sing-box-linux-amd64',
        'darwin-arm64' => 'sing-box-darwin-arm64',
        'darwin-amd64' => 'sing-box-darwin-amd64',
    ];

    public function singbox(string $target): Response
    {
        abort_unless(isset(self::MAP[$target]), 404);

        $path = storage_path('app/bin/' . self::MAP[$target]);
        abort_unless(is_file($path), 503, 'sing-box binary not uploaded for ' . $target);

        return response()->download($path, self::MAP[$target], [
            'Content-Type' => 'application/octet-stream',
            'X-Singbox-Version' => trim(@file_get_contents(storage_path('app/bin/VERSION')) ?: 'unknown'),
        ]);
    }

    public function version(): Response
    {
        $v = @file_get_contents(storage_path('app/bin/VERSION'));

        return response()->json(['version' => trim($v ?: '') ?: null]);
    }
}
