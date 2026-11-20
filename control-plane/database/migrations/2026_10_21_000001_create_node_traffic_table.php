<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bytes the node's network interface moved per day (UTC), and the
        // busiest 15-second rate the agent saw, so the admin can see a node
        // filling up before customers feel it.
        Schema::create('node_traffic', function (Blueprint $t) {
            $t->id();
            $t->foreignId('node_id')->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->unsignedBigInteger('rx_bytes')->default(0);
            $t->unsignedBigInteger('tx_bytes')->default(0);
            $t->decimal('peak_rx_mbps', 8, 1)->default(0);
            $t->decimal('peak_tx_mbps', 8, 1)->default(0);
            $t->timestamps();
            $t->unique(['node_id', 'day']);
        });

        Schema::table('nodes', function (Blueprint $t) {
            // The hosting plan's monthly data allowance and the day of the
            // month it resets (the provider's billing cycle).
            $t->unsignedInteger('monthly_quota_gb')->nullable()->after('capacity');
            $t->unsignedTinyInteger('quota_reset_day')->default(1)->after('monthly_quota_gb');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $t) {
            $t->dropColumn(['monthly_quota_gb', 'quota_reset_day']);
        });
        Schema::dropIfExists('node_traffic');
    }
};
