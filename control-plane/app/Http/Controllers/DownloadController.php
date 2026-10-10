<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public "get the app" page. Its QR code (/download/qr.svg) only ever
 * encodes this page's own URL — never an account, token or VPN config.
 */
class DownloadController extends Controller
{
    public function page(): View
    {
        return view('download', [
            'android' => url(config('services.mvpn.download_android')),
            'windows' => url(config('services.mvpn.download_windows')),
            'version' => config('services.mvpn.app_version'),
        ]);
    }

    public function qr(): Response
    {
        $options = new \chillerlan\QRCode\QROptions([
            'outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'addQuietzone' => true,
        ]);
        $svg = (new \chillerlan\QRCode\QRCode($options))->render(route('download'));

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
