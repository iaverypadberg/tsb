<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a route an identifier that is safe to put in a URL.
 *
 * The primary key is a counter, and a counter in a link says how many routes
 * the site holds and roughly when this one arrived in the pile. Links are
 * built from this instead; `id` stays the key everything internal joins on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->after('id');
        });

        DB::statement('update routes set public_id = gen_random_uuid() where public_id is null');

        Schema::table('routes', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable(false)->change();
            $table->unique('public_id');
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
