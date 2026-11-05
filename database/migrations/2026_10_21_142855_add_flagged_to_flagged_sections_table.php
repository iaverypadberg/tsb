<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The table now holds every stretch of a route rather than only the bad
     * ones, so a rider can see the whole ride accounted for: what was flagged
     * and, between the flags, what was not.
     *
     * Defaulting to true keeps the rows an earlier analysis wrote meaning
     * what they meant when they were written.
     */
    public function up(): void
    {
        Schema::table('flagged_sections', function (Blueprint $table) {
            $table->boolean('flagged')->default(true)->after('max_danger');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flagged_sections', function (Blueprint $table) {
            $table->dropColumn('flagged');
        });
    }
};
