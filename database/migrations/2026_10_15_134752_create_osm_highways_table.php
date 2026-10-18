<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Raw highway ways as they come out of OpenStreetMap, before any
     * interpretation. Keeping the untouched tags here means `road_segments`
     * can be rebuilt and rescored without downloading the extract again.
     *
     * osm2pgsql owns this table during an import and will drop and recreate
     * it, so this shape must stay in step with database/osm/highways.lua.
     */
    public function up(): void
    {
        Schema::create('osm_highways', function (Blueprint $table) {
            $table->unsignedBigInteger('osm_way_id')->primary();
            $table->text('name')->nullable();
            $table->text('highway');
            $table->text('maxspeed')->nullable();
            $table->text('lanes')->nullable();
            $table->text('shoulder')->nullable();
            $table->text('cycleway')->nullable();
            $table->text('cycleway_left')->nullable();
            $table->text('cycleway_right')->nullable();
            $table->text('cycleway_both')->nullable();
            $table->text('bicycle')->nullable();
            $table->text('surface')->nullable();
            $table->text('oneway')->nullable();
            $table->text('access')->nullable();
            $table->magellanLineString('geom', 4326);
        });

        Schema::table('osm_highways', function (Blueprint $table) {
            $table->spatialIndex('geom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('osm_highways');
    }
};
