<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second Hysteria2 listener with Salamander obfuscation (looks like random
 * UDP instead of QUIC). It runs next to the plain one, so apps holding an old
 * config keep working. The node reports these through node-info.json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $t) {
            if (! Schema::hasColumn('nodes', 'hysteria_obfs_port')) {
                $t->unsignedInteger('hysteria_obfs_port')->nullable();
            }
            if (! Schema::hasColumn('nodes', 'hysteria_obfs_port_range')) {
                $t->string('hysteria_obfs_port_range')->nullable();
            }
            if (! Schema::hasColumn('nodes', 'hysteria_obfs_password')) {
                $t->text('hysteria_obfs_password')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $t) {
            $t->dropColumn(['hysteria_obfs_port', 'hysteria_obfs_port_range', 'hysteria_obfs_password']);
        });
    }
};
