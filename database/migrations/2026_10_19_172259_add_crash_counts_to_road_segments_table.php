<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The matched crash totals are denormalised onto the road so scoring can
     * read them without a join, the same way the traffic count is, and
     * `crashes.road_segment_id` keeps the working out.
     *
     * `osm:build-segments` leaves these columns alone on an upsert, so an
     * extract refresh keeps the totals. It does replace geometry though, so
     * `crashes:match` should be re-run after an import.
     */
    public function up(): void
    {
        Schema::table('road_segments', function (Blueprint $table) {
            $table->unsignedInteger('crash_count')->default(0)->index();

            // Injuries and deaths separately, because "three crashes" and
            // "three crashes, one of them fatal" are not the same road.
            $table->unsignedInteger('crash_injury_count')->default(0);
            $table->unsignedInteger('crash_fatal_count')->default(0);

            // The span the matched crashes were drawn from, so a count can be
            // read as a rate rather than as a bare number.
            $table->unsignedSmallInteger('crash_first_year')->nullable();
            $table->unsignedSmallInteger('crash_last_year')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('road_segments', function (Blueprint $table) {
            $table->dropColumn([
                'crash_count', 'crash_injury_count', 'crash_fatal_count',
                'crash_first_year', 'crash_last_year',
            ]);
        });
    }
};
