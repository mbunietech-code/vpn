<?php

use App\Http\Controllers\SubscriptionLinkController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'Mbunie VPN control plane',
    'status' => 'ok',
]));

// Opaque client subscription endpoint (FR-NEW-05)
Route::get('/sub/{token}', SubscriptionLinkController::class)
    ->where('token', '[A-Za-z0-9]+');

// Payment webhooks - no CSRF, signature-verified inside the processor
Route::post('/webhooks/stripe', [WebhookController::class, 'stripe']);
Route::post('/webhooks/cryptomus', [WebhookController::class, 'cryptomus']);

// Lightweight return page the hosted checkout redirects back to
Route::get('/pay/return', function () {
    return response(
        '<!doctype html><meta charset="utf-8"><title>Mbunie VPN</title>'
        . '<body style="font:16px system-ui;text-align:center;padding:16vh 24px">'
        . '<h2>Malipo yamepokelewa</h2><p>Unaweza kufunga ukurasa huu na kurudi kwenye app.</p>'
        . '<script>setTimeout(()=>{location.href="mvpn://payment/return"},800)</script>'
    );
})->name('pay.return');
