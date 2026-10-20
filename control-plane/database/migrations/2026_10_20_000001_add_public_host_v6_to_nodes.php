<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // A host with only an AAAA record: clients get the same REALITY /
            // Hysteria2 credentials over IPv6 as an extra "(IPv6)" server.
            $table->string('public_host_v6')->nullable()->after('public_host');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('public_host_v6');
        });
    }
};
