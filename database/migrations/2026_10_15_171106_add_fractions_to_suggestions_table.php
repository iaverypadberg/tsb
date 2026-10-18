<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A detour leaves and rejoins the route a little outside the flagged
     * section. Recording exactly where means the export can cut the old line
     * at the same points it was routed between, rather than recomputing them
     * from a constant that may since have changed.
     */
    public function up(): void
    {
        Schema::table('suggestions', function (Blueprint $table) {
            $table->double('start_fraction')->default(0)->after('flagged_section_id');
            $table->double('end_fraction')->default(0)->after('start_fraction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suggestions', function (Blueprint $table) {
            $table->dropColumn(['start_fraction', 'end_fraction']);
        });
    }
};
