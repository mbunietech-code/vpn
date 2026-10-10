<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // users.id on MbunieEduHub (mbuniehub.com) — set by SSO or a partner activation.
            $table->unsignedBigInteger('eduhub_user_id')->nullable()->unique()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['eduhub_user_id']);
            $table->dropColumn('eduhub_user_id');
        });
    }
};
