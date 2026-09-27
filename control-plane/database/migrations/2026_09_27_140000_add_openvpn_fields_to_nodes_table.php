<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->longText('openvpn_config')->nullable()->after('hysteria_cert_pem');
            $table->string('openvpn_username')->nullable()->after('openvpn_config');
            $table->string('openvpn_password')->nullable()->after('openvpn_username');
            $table->string('openvpn_server_name')->nullable()->after('openvpn_password');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn([
                'openvpn_config',
                'openvpn_username',
                'openvpn_password',
                'openvpn_server_name',
            ]);
        });
    }
};
