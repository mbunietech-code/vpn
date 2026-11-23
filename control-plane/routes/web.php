<?php

use App\Http\Controllers\BinaryController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\SubscriptionLinkController;
use App\Http\Controllers\VipController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\RejectRevokedDevice;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'Mbunie VPN control plane',
    'status' => 'ok',
]));

// Opaque client subscription endpoint (FR-NEW-05)
Route::get('/sub/{token}', SubscriptionLinkController::class)
    ->where('token', '[A-Za-z0-9]+')
    ->middleware(RejectRevokedDevice::class);

// sing-box engine binary for the desktop app (China can't rely on GitHub)
Route::get('/bin/sing-box/{target}', [BinaryController::class, 'singbox'])
    ->where('target', '[a-z0-9-]+');
Route::get('/bin/sing-box-version', [BinaryController::class, 'version']);

// Payment webhooks - no CSRF, signature-verified inside the processor
Route::post('/webhooks/stripe', [WebhookController::class, 'stripe']);
Route::post('/webhooks/cryptomus', [WebhookController::class, 'cryptomus']);
Route::post('/webhooks/clickpesa', [WebhookController::class, 'clickpesa']);
// Pesapal IPN: unsigned "order X changed" (GET by default); status re-checked on its API.
Route::match(['get', 'post'], '/webhooks/pesapal', [WebhookController::class, 'pesapal'])->name('webhooks.pesapal');

// VIP event passes: scan the QR, pay per device, the app signs in with the code.
Route::prefix('vip')->controller(VipController::class)->group(function () {
    Route::get('p/{token}', 'pass')->name('vip.pass');
    Route::get('p/{token}/status', 'status')->name('vip.pass.status')->middleware('throttle:60,1,vip-status');
    Route::post('p/{token}/proof', 'proof')->name('vip.pass.proof')->middleware('throttle:10,60,vip-proof');
    Route::get('p/{token}/qr.svg', 'passQr')->name('vip.pass.qr');
    Route::get('{event:slug}', 'event')->name('vip.event');
    Route::post('{event:slug}/checkout', 'checkout')->name('vip.checkout')->middleware('throttle:10,1,vip-checkout');
    Route::get('{event:slug}/qr.svg', 'eventQr')->name('vip.event.qr');
});

// Payment-proof image — admins only
Route::get('/admin/invoice/{invoice}/proof', function (Invoice $invoice) {
    abort_unless(auth()->user()?->is_admin && $invoice->proof_path, 403);

    return response()->file(storage_path('app/private/'.$invoice->proof_path));
})->middleware('auth')->name('admin.invoice.proof');

// Legal pages (owner MUST review with counsel — these are starting drafts)
Route::view('/legal/terms', 'legal.terms')->name('legal.terms');
Route::view('/legal/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/legal/delete-account', 'legal.delete-account')->name('legal.delete-account');

// Public app download page + a QR of its own URL (linked from MbunieHub).
Route::get('/download', [DownloadController::class, 'page'])->name('download');
Route::get('/download/qr.svg', [DownloadController::class, 'qr'])->name('download.qr');
Route::get('/download/android-qr.svg', [DownloadController::class, 'androidQr'])->name('download.qr.android');

// Lightweight return page the hosted checkout redirects back to
Route::get('/pay/return', function (Request $request) {
    [$title, $text] = $request->query('status') === 'cancel'
        ? ['Payment cancelled', 'Nothing was charged. Go back to the app to try again.']
        : ['Payment received', 'Go back to the Mbunie VPN app: it connects by itself in a few seconds.'];

    return response(
        '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mbunie VPN</title>'
        .'<body style="font:16px system-ui;text-align:center;padding:16vh 24px">'
        ."<h2>{$title}</h2><p>{$text}</p>"
        .'<script>setTimeout(()=>{location.href="mvpn://payment/return"},800)</script>'
    );
})->name('pay.return');
