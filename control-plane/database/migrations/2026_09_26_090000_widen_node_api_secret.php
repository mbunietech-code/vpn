<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * api_secret uses the `encrypted` cast: a 48-char token encrypts to ~288
 * chars, which MySQL (strict) rejects in a varchar(255). SQLite in tests
 * never enforced the length, so this only surfaced in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $t) {
            $t->text('api_secret')->change();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $t) {
            $t->string('api_secret')->change();
        });
    }
};
