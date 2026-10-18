<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peers', function (Blueprint $table) {
            // Per-subscription OpenVPN client profile. The node agent issues the
            // certificate (the CA never leaves the node) and uploads the profile;
            // the serial tells it whether we already have the current one.
            $table->longText('openvpn_profile')->nullable();
            $table->string('openvpn_cert_serial', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('peers', function (Blueprint $table) {
            $table->dropColumn(['openvpn_profile', 'openvpn_cert_serial']);
        });
    }
};
