<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1 payment flow: the user pays a QR / bank transfer out-of-band, uploads
 * proof, and an admin approves — which runs the same provisioning pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $t->string('type');                 // alipay | wechat | bank | crypto | other
            $t->string('label');                // "Alipay (¥)" shown to the user
            $t->string('currency', 3)->nullable(); // usd | cny | null = any
            $t->string('qr_path')->nullable();  // storage/app/public/qr/...
            $t->text('instructions')->nullable();
            $t->string('account_ref')->nullable(); // account no / address shown as text
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::table('invoices', function (Blueprint $t) {
            $t->string('proof_path')->nullable()->after('meta');   // storage/app/proofs/...
            $t->string('payment_method')->nullable()->after('provider');
            $t->timestamp('reviewed_at')->nullable();
            $t->string('reviewed_by')->nullable();
            $t->text('review_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn(['proof_path', 'payment_method', 'reviewed_at', 'reviewed_by', 'review_note']);
        });
    }
};
