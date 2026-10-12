<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\DevController;
use App\Http\Controllers\Api\NodeController;
use App\Http\Controllers\Api\PartnerAdminController;
use App\Http\Controllers\Api\PartnerController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\DownloadController;
use App\Http\Middleware\RejectRevokedDevice;
use App\Http\Middleware\VerifyPartnerSignature;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| App-facing API  (base: /api)
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->middleware(RejectRevokedDevice::class)->group(function () {
    Route::post('otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:10,1');
    Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
});

Route::get('plans', [PlanController::class, 'index']);
Route::get('payment-methods', [PaymentController::class, 'methods']);
Route::get('app/version', [DownloadController::class, 'appVersion']);

Route::middleware(['auth:sanctum', RejectRevokedDevice::class])->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Automatic providers (Stripe / Cryptomus) — kept for v2
    Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:20,60');

    // v1 manual flow
    Route::post('checkout/manual', [PaymentController::class, 'createManualInvoice'])
        ->middleware('throttle:20,60');
    Route::post('invoices/{invoice}/proof', [PaymentController::class, 'uploadProof'])
        ->middleware('throttle:10,60');

    Route::get('subscription', [SubscriptionController::class, 'show']);
    Route::post('subscription/device', [SubscriptionController::class, 'registerDevice']);

    if (App::environment('local')) {
        Route::post('dev/pay', [DevController::class, 'pay']);
    }
});

/*
|--------------------------------------------------------------------------
| Node-agent API  (bearer = per-node secret)
|--------------------------------------------------------------------------
*/
Route::prefix('node')->group(function () {
    Route::get('peers', [NodeController::class, 'peers']);
    Route::post('health', [NodeController::class, 'health']);
});

/*
|--------------------------------------------------------------------------
| Partner API for MbunieEduHub  (HMAC-signed, see VerifyPartnerSignature)
|--------------------------------------------------------------------------
*/
Route::prefix('partner')
    ->middleware([VerifyPartnerSignature::class, 'throttle:120,1'])
    ->group(function () {
        Route::get('stats', [PartnerController::class, 'stats']);
        Route::get('customers/{eduhubUserId}', [PartnerController::class, 'customer'])->whereNumber('eduhubUserId');
        Route::post('activations', [PartnerController::class, 'activate']);

        // VPN management from the EduHub admin (see PartnerAdminController).
        Route::prefix('admin')->controller(PartnerAdminController::class)->group(function () {
            Route::get('customers', 'customers');
            Route::get('customers/{user}', 'customer')->whereNumber('user');
            Route::post('customers/{user}/status', 'setCustomerStatus')->whereNumber('user');
            Route::post('customers/{user}/grant', 'grant')->whereNumber('user');
            Route::post('subscriptions/{subscription}/extend', 'extend')->whereNumber('subscription');
            Route::post('subscriptions/{subscription}/suspend', 'suspend')->whereNumber('subscription');
            Route::post('subscriptions/{subscription}/restore', 'restore')->whereNumber('subscription');
            Route::get('devices', 'devices');
            Route::post('devices/{device}/remove', 'removeDevice')->whereNumber('device');
            Route::post('devices/{device}/allow', 'allowDevice')->whereNumber('device');
            Route::get('invoices', 'invoices');
            Route::get('invoices/{invoice}/proof', 'proof')->whereNumber('invoice');
            Route::post('invoices/{invoice}/approve', 'approve')->whereNumber('invoice');
            Route::post('invoices/{invoice}/reject', 'reject')->whereNumber('invoice');
            Route::get('plans', 'plans');
            Route::put('plans/{plan}', 'updatePlan')->whereNumber('plan');
        });
    });
