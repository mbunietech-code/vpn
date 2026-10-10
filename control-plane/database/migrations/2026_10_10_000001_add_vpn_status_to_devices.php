<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Live state reported by the app's heartbeat while the tunnel is up.
            $table->boolean('vpn_connected')->default(false)->after('platform');
            $table->timestamp('connected_at')->nullable()->after('vpn_connected');
            $table->string('protocol', 32)->nullable()->after('connected_at');
            $table->string('app_version', 32)->nullable()->after('protocol');
            $table->index(['vpn_connected', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['vpn_connected', 'last_seen_at']);
            $table->dropColumn(['vpn_connected', 'connected_at', 'protocol', 'app_version']);
        });
    }
};
