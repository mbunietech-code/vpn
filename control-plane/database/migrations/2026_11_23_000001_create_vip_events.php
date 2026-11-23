<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VIP event passes: a guest scans the event QR, pays per device and gets
 * into the app with a code, no account. Access ends when the event ends
 * (the admin sets the end time and can switch every pass off at once).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vip_events')) {
            Schema::create('vip_events', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('slug')->unique();          // public page: /vip/{slug}
                $t->text('description')->nullable();   // shown on the page
                $t->unsignedInteger('price_cny_cents')->default(2500); // per device
                $t->unsignedInteger('price_usd_cents')->default(0);
                $t->unsignedInteger('price_tzs_cents')->default(0);
                $t->unsignedTinyInteger('max_devices_per_order')->default(5);
                $t->json('providers')->nullable();     // allowed payment providers; null = all with keys
                $t->dateTime('ends_at');               // every pass stops working here (dateTime: MariaDB never auto-updates it)
                $t->string('timezone', 64)->default('Asia/Shanghai'); // how times are typed and shown
                $t->boolean('sales_open')->default(true);
                $t->boolean('access_enabled')->default(true); // master switch for every pass
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('vip_passes')) {
            Schema::create('vip_passes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('vip_event_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
                $t->string('code', 16)->unique();       // typed / scanned into the app
                $t->string('page_token', 64)->unique(); // the buyer's private pass page
                $t->unsignedTinyInteger('devices')->default(1);
                $t->string('status')->default('pending'); // pending | active | cancelled
                $t->string('source')->default('paid');    // paid | manual (given by an admin)
                $t->boolean('blocked')->default(false);   // admin switched this one pass off
                $t->string('contact')->nullable();        // buyer's email / phone (optional)
                $t->string('note')->nullable();
                $t->timestamp('activated_at')->nullable();
                $t->timestamps();
                $t->index(['vip_event_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vip_passes');
        Schema::dropIfExists('vip_events');
    }
};
