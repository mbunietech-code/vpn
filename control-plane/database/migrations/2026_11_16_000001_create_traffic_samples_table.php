<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-session traffic from the node agents (>= 0.5.0), in 5-minute buckets:
 * bytes, peak speed and bytes per coarse traffic kind (calls, video, …).
 * Short-lived on purpose (see TrafficRecorder::RETENTION_HOURS): no host
 * names, only categories, and pruned after a few days.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('traffic_samples')) {
            Schema::create('traffic_samples', function (Blueprint $t) {
                $t->id();
                $t->foreignId('node_id')->constrained()->cascadeOnDelete();
                $t->foreignId('peer_id')->constrained()->cascadeOnDelete();
                $t->unsignedBigInteger('subscription_id')->index();
                // Best guess of which of the subscription's devices this was (null = unknown).
                $t->unsignedBigInteger('device_id')->nullable();
                $t->string('protocol', 32);
                $t->string('inbound', 64)->default('');
                $t->string('source_ip', 45)->default('');
                $t->timestamp('bucket_at');
                $t->unsignedBigInteger('bytes_up')->default(0);
                $t->unsignedBigInteger('bytes_down')->default(0);
                $t->decimal('peak_up_mbps', 8, 2)->default(0);
                $t->decimal('peak_down_mbps', 8, 2)->default(0);
                // Average speed of the last report (≈ last minute): "speed now".
                $t->decimal('last_up_mbps', 8, 2)->default(0);
                $t->decimal('last_down_mbps', 8, 2)->default(0);
                $t->unsignedInteger('connections')->default(0);
                $t->json('categories')->nullable(); // {"video": [up, down], ...}
                $t->timestamps();

                $t->unique(['peer_id', 'inbound', 'source_ip', 'bucket_at'], 'traffic_samples_session_bucket');
                $t->index('bucket_at');
                $t->index(['device_id', 'bucket_at']);
            });
        }

        Schema::table('devices', function (Blueprint $t) {
            if (! Schema::hasColumn('devices', 'last_ip')) {
                // Where the app's heartbeat came from; matched against the
                // source IP the node sees, to tell a customer's devices apart.
                $t->string('last_ip', 45)->nullable()->after('last_seen_at');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_samples');
        Schema::table('devices', function (Blueprint $t) {
            if (Schema::hasColumn('devices', 'last_ip')) {
                $t->dropColumn('last_ip');
            }
        });
    }
};
