<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Free trial: every new customer gets the "trial" plan once, automatically,
 * the first time they sign in. It is never sold, and it disappears from the
 * plan list once it has run out.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so a half-applied run (MySQL DDL is not transactional) can be re-run.
        if (! Schema::hasColumn('plans', 'is_trial')) {
            Schema::table('plans', function (Blueprint $t) {
                $t->boolean('is_trial')->default(false)->after('is_active');
            });
        }

        if (! Schema::hasColumn('users', 'trial_started_at')) {
            Schema::table('users', function (Blueprint $t) {
                $t->timestamp('trial_started_at')->nullable();
            });
        }
        if (! Schema::hasColumn('users', 'trial_device')) {
            Schema::table('users', function (Blueprint $t) {
                // Device that claimed the trial: one free trial per phone, not per email.
                $t->string('trial_device')->nullable()->index();
            });
        }

        DB::table('plans')->updateOrInsert(['code' => 'trial'], [
            'name' => 'Jaribio Bure Siku 2',
            'days' => 2,
            'max_devices' => 1,
            'data_cap_mb' => null,
            'node_scope' => null,
            'price_usd_cents' => 0,
            'price_cny_cents' => 0,
            'price_tzs_cents' => 0,
            'sort' => 0,   // column is unsigned on MySQL
            'is_active' => true,
            'is_trial' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('plans')->where('code', 'trial')->delete();

        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['trial_device']);
            $t->dropColumn(['trial_started_at', 'trial_device']);
        });

        Schema::table('plans', function (Blueprint $t) {
            $t->dropColumn('is_trial');
        });
    }
};
