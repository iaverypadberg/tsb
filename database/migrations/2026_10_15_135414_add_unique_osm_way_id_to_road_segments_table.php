<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Rebuilding segments has to update rows in place rather than replace
     * them: hand-written `segment_reports` cascade on delete, so recreating a
     * segment would silently discard the local knowledge attached to it.
     * A unique key on the way id gives the rebuild something to upsert on.
     *
     * Segments added by hand carry a null way id, and Postgres treats nulls
     * as distinct, so any number of them can coexist.
     */
    public function up(): void
    {
        Schema::table('road_segments', function (Blueprint $table) {
            $table->dropIndex(['osm_way_id']);
            $table->unique('osm_way_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('road_segments', function (Blueprint $table) {
            $table->dropUnique(['osm_way_id']);
            $table->index('osm_way_id');
        });
    }
};
