<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RejectRevokedDevice;
use App\Models\Device;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public "get the app" page. Its QR codes only ever encode public download
 * links (this page, the APK) — never an account, token or VPN config.
 */
class DownloadController extends Controller
{
    public function page(): View
    {
        return view('download', [
            'android' => url(config('services.mvpn.download_android')),
            'windows' => url(config('services.mvpn.download_windows')),
            'macos' => url(config('services.mvpn.download_macos')),
            'version' => config('services.mvpn.app_version'),
            'androidVersion' => config('services.mvpn.android_version') ?: config('services.mvpn.app_version'),
            'windowsVersion' => config('services.mvpn.windows_version') ?: config('services.mvpn.app_version'),
            'androidDate' => $this->releaseDate(config('services.mvpn.download_android')),
            'windowsDate' => $this->releaseDate(config('services.mvpn.download_windows')),
            'macosDate' => $this->releaseDate(config('services.mvpn.download_macos')),
        ]);
    }

    /** Upload date of an installer we host ourselves, e.g. 2026-10-12. */
    private function releaseDate(?string $path): ?string
    {
        if (! $path || ! str_starts_with($path, '/storage/downloads/')) {
            return null;
        }
        $file = storage_path('app/public/downloads/'.basename($path));

        return is_file($file) ? date('Y-m-d', filemtime($file)) : null;
    }

    /**
     * What the app checks on start: the newest version and where to get it.
     * Apps below `min_supported` must update before connecting.
     *
     * Older apps only read `latest`, so the platform comes from the device the
     * app identifies itself as: Android can be ahead of the desktop builds
     * without telling desktop users to update to an installer that isn't out.
     */
    public function appVersion(Request $request): JsonResponse
    {
        $deviceId = (string) $request->header(RejectRevokedDevice::HEADER, '');
        $platform = $deviceId !== '' ? Device::where('fingerprint', $deviceId)->value('platform') : null;
        $latest = match ($platform) {
            'android' => config('services.mvpn.android_version'),
            'windows' => config('services.mvpn.windows_version'),
            default => null,
        } ?: config('services.mvpn.app_version');

        return response()->json([
            'latest' => (string) $latest,
            'min_supported' => config('services.mvpn.min_app_version') ?: null,
            'android' => url(config('services.mvpn.download_android')),
            'windows' => url(config('services.mvpn.download_windows')),
            'macos' => url(config('services.mvpn.download_macos')),
            'page' => route('download'),
        ])->header('Cache-Control', 'private, max-age=300'); // differs per device
    }

    /** QR of the Android APK link, for scanning the page from a PC. */
    public function androidQr(): Response
    {
        return $this->svg(url(config('services.mvpn.download_android')));
    }

    public function qr(): Response
    {
        return $this->svg(route('download'));
    }

    private function svg(string $text): Response
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'addQuietzone' => true,
        ]);
        $svg = (new QRCode($options))->render($text);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
