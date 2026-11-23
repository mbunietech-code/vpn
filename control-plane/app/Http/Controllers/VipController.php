<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use App\Models\VipEvent;
use App\Models\VipPass;
use App\Services\PaymentAlerts;
use App\Services\VipPasses;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

/**
 * Public VIP event pages (no account):
 *   /vip/{slug}          scan the event QR → pick devices → pay
 *   /vip/p/{token}       the buyer's own pass: status, code, app download
 *
 * The pass page token is the buyer's receipt; the code inside signs the app
 * in. Neither page ever shows another buyer's pass.
 */
class VipController extends Controller
{
    public function __construct(private VipPasses $passes) {}

    public function event(Request $request, VipEvent $event): View
    {
        $this->locale($request);

        // The pass bought on this phone (the provider may not send the buyer back).
        $mine = null;
        if ($token = $request->cookie($this->cookieName($event))) {
            $mine = $event->passes()->where('page_token', $token)->first();
        }

        return view('vip.event', [
            'event' => $event,
            'options' => $event->isSelling() ? $event->paymentOptions() : [],
            'mine' => $mine,
        ]);
    }

    public function checkout(Request $request, VipEvent $event): RedirectResponse
    {
        abort_unless($event->isSelling(), 410);

        $options = collect($event->paymentOptions())->keyBy('key');
        $data = $request->validate([
            'devices' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) $event->max_devices_per_order)],
            'method' => ['required', 'string', 'in:'.$options->keys()->implode(',')],
            'contact' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            ['pass' => $pass, 'pay_url' => $payUrl] = $this->passes->buy(
                $event, $options[$data['method']], (int) $data['devices'], trim((string) ($data['contact'] ?? '')) ?: null,
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['method' => $e->getMessage()]);
        }

        Cookie::queue($this->cookieName($event), $pass->page_token, 60 * 24 * 30);

        return $payUrl ? redirect()->away($payUrl) : redirect()->route('vip.pass', $pass->page_token);
    }

    public function pass(Request $request, string $token): View
    {
        $this->locale($request);
        $pass = $this->find($token);
        $this->passes->refreshPayment($pass);
        $pass->refresh()->load('event', 'invoice', 'subscription');

        $method = null;
        if ($pass->invoice?->provider === 'manual') {
            $method = PaymentMethod::where('label', $pass->invoice->payment_method)->first();
        }

        $ua = strtolower((string) $request->userAgent());
        $platform = match (true) {
            str_contains($ua, 'iphone') || str_contains($ua, 'ipad') => 'ios',
            str_contains($ua, 'android') => 'android',
            str_contains($ua, 'windows') => 'windows',
            str_contains($ua, 'mac os') => 'macos',
            default => 'other',
        };

        $sub = $pass->subscription;
        $iosProfile = $sub && $pass->usable()
            ? url("/sub/{$sub->sub_token}").'?format=singbox&platform=ios&protocol=auto'
            : null;

        return view('vip.pass', [
            'pass' => $pass,
            'event' => $pass->event,
            'state' => $pass->state(),
            'method' => $method,
            'platform' => $platform,
            'android' => url(config('services.mvpn.download_android')),
            'windows' => url(config('services.mvpn.download_windows')),
            'macos' => url(config('services.mvpn.download_macos')),
            'iosImport' => $iosProfile
                ? 'sing-box://import-remote-profile?url='.rawurlencode($iosProfile).'#'.rawurlencode('Mbunie VPN')
                : null,
            'cancelled' => $request->boolean('cancelled') && $pass->status === 'pending',
        ]);
    }

    /** Polled by the pass page while it waits for the payment. */
    public function status(string $token): JsonResponse
    {
        $pass = $this->find($token);
        $this->passes->refreshPayment($pass);

        return response()->json(['state' => $pass->refresh()->state()])
            ->header('Cache-Control', 'no-store');
    }

    /** Manual payment (Alipay QR etc.): the buyer's screenshot, for an admin to approve. */
    public function proof(Request $request, string $token): RedirectResponse
    {
        $pass = $this->find($token);
        $invoice = $pass->invoice;
        abort_unless($invoice && $invoice->provider === 'manual'
            && in_array($invoice->status, ['pending', 'pending_review'], true), 409);

        $request->validate([
            'proof' => ['required', 'image', 'max:8192'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $path = $request->file('proof')->store("proofs/{$invoice->id}");
        $invoice->update([
            'proof_path' => $path,
            'status' => 'pending_review',
            'meta' => array_merge($invoice->meta ?? [], [
                'user_note' => $request->string('note')->toString(),
                'proof_uploaded_at' => now()->toIso8601String(),
            ]),
        ]);

        app(PaymentAlerts::class)->proofUploaded($invoice->refresh());

        return redirect()->route('vip.pass', $pass->page_token);
    }

    /** QR of the event page, to print or show on screen at the venue. */
    public function eventQr(VipEvent $event): Response
    {
        return $this->svg($event->publicUrl(), 'public, max-age=3600');
    }

    /** QR of a pass page, to open it on the guest's other phone. */
    public function passQr(string $token): Response
    {
        return $this->svg($this->find($token)->pageUrl(), 'private, no-store');
    }

    private function find(string $token): VipPass
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        return VipPass::with('event', 'invoice', 'subscription')->where('page_token', $token)->firstOrFail();
    }

    private function cookieName(VipEvent $event): string
    {
        return 'vip_pass_'.$event->id;
    }

    /** ?lang=zh|sw|en (remembered), else the browser's language. */
    private function locale(Request $request): void
    {
        $supported = ['en', 'zh', 'sw'];
        $lang = $request->query('lang');
        if (is_string($lang) && in_array($lang, $supported, true)) {
            Cookie::queue('vip_lang', $lang, 60 * 24 * 365);
        } else {
            $lang = $request->cookie('vip_lang');
            if (! in_array($lang, $supported, true)) {
                $lang = $request->getPreferredLanguage(['en', 'zh', 'zh_CN', 'sw']) ?? 'en';
                $lang = str_starts_with($lang, 'zh') ? 'zh' : (in_array($lang, $supported, true) ? $lang : 'en');
            }
        }
        app()->setLocale($lang);
    }

    private function svg(string $text, string $cache): Response
    {
        $svg = (new QRCode(new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'addQuietzone' => true,
        ])))->render($text);

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => $cache]);
    }
}
