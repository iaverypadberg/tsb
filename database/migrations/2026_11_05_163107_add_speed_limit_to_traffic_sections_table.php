<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * How fast the traffic on a stretch is allowed to go, which is the other
     * half of the question a count answers. 400 vehicles a day on a 50 km/h
     * lane and 400 on an 88 km/h state highway are not the same road to ride,
     * and until now the profile could not tell them apart.
     *
     * Null is the ordinary case rather than an edge one: OpenStreetMap carries
     * a speed limit on about one Vermont way in twenty, so most stretches say
     * nothing here and the band and the count still stand on their own.
     *
     * Kept in km/h to match `road_segments.speed_limit_kmh`, which is where
     * the figure is copied from; the tags it is read out of are in mph.
     *
     * Routes profiled before this column existed keep a null limit until the
     * next time they are profiled; nothing re-runs on their behalf.
     */
    public function up(): void
    {
        Schema::table('traffic_sections', function (Blueprint $table) {
            $table->unsignedSmallInteger('speed_limit_kmh')->nullable()->after('road_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('traffic_sections', function (Blueprint $table) {
            $table->dropColumn('speed_limit_kmh');
        });
    }
};
