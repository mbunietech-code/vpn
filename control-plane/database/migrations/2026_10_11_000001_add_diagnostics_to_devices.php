<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Reported by the app so failures on far-away phones are visible in admin.
            $table->string('model', 120)->nullable()->after('name');
            $table->string('os_version', 120)->nullable()->after('model');
            $table->text('last_error')->nullable()->after('app_version');
            $table->timestamp('last_error_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['model', 'os_version', 'last_error', 'last_error_at']);
        });
    }
};
