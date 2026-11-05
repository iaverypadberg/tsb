<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What a rider would call the road a stretch runs on, so the traffic
     * profile can be read as a list of roads rather than of anonymous
     * stretches: "VT 100 — 14,200 a day" is something somebody can act on,
     * "stretch 7 of 23" is not.
     *
     * Null where the road carries neither a route number nor a name, which
     * is most of the lanes here. The band and the count still stand on their
     * own, so an unnamed stretch is listed rather than hidden.
     *
     * Routes profiled before this column existed keep a null name until the
     * next time they are profiled; nothing re-runs on their behalf.
     */
    public function up(): void
    {
        Schema::table('traffic_sections', function (Blueprint $table) {
            $table->string('road_name')->nullable()->after('band');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('traffic_sections', function (Blueprint $table) {
            $table->dropColumn('road_name');
        });
    }
};
