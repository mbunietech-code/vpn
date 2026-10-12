<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // The expires_at each reminder was sent for: renewing moves expires_at,
            // so the reminders arm themselves again for the new end date.
            $table->timestamp('reminded_3d_for')->nullable();
            $table->timestamp('reminded_1d_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['reminded_3d_for', 'reminded_1d_for']);
        });
    }
};
