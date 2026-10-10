<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Set once the paid invoice has extended a subscription, so a retried
            // job / duplicate webhook can never add the plan's days twice.
            $table->timestamp('provisioned_at')->nullable()->after('paid_at');
            $table->foreignId('subscription_id')->nullable()->after('provisioned_at')
                ->constrained()->nullOnDelete();
        });

        // Invoices already paid before this column existed were provisioned then.
        \Illuminate\Support\Facades\DB::table('invoices')
            ->where('status', 'paid')->whereNull('provisioned_at')
            ->update(['provisioned_at' => \Illuminate\Support\Facades\DB::raw('paid_at')]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
            $table->dropColumn('provisioned_at');
        });
    }
};
