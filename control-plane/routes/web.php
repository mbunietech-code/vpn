<?php

use App\Http\Controllers\BinaryController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\SubscriptionLinkController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\RejectRevokedDevice;
use App\Models\Invoice;
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

// Payment-proof image — admins only
Route::get('/admin/invoice/{invoice}/proof', function (Invoice $invoice) {
    abort_unless(auth()->user()?->is_admin && $invoice->proof_path, 403);

    return response()->file(storage_path('app/private/'.$invoice->proof_path));
})->middleware('auth')->name('admin.invoice.proof');

// Legal pages (owner MUST review with counsel — these are starting drafts)
Route::view('/legal/terms', 'legal.terms')->name('legal.terms');
Route::view('/legal/privacy', 'legal.privacy')->name('legal.privacy');

// Public app download page + a QR of its own URL (linked from MbunieHub).
Route::get('/download', [DownloadController::class, 'page'])->name('download');
Route::get('/download/qr.svg', [DownloadController::class, 'qr'])->name('download.qr');

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
