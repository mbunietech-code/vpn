<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nodes register their own protocol parameters (REALITY key, Hysteria2 cert…)
 * through the agent's health report, so the admin only creates a row with a
 * name, region and token. Ports are per node so a node can share a box with
 * a web server that already owns 443. api_base was never used - the control plane never
 * calls the node.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $t) {
            $t->string('api_base')->nullable()->change();
            $t->unsignedInteger('reality_port')->default(443)->after('reality_sni');
            $t->unsignedInteger('hysteria_port')->default(443)->after('reality_port');
            $t->text('hysteria_cert_pem')->nullable()->after('hysteria_cert_sha256');
            $t->string('agent_version')->nullable()->after('health');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $t) {
            $t->dropColumn(['reality_port', 'hysteria_port', 'hysteria_cert_pem', 'agent_version']);
        });
    }
};
