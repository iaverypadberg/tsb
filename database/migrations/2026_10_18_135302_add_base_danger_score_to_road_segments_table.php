<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `danger_score` is what the tags say about a road plus whatever local
     * knowledge has been recorded against it. Keeping the tag-only part
     * means a newly accepted report can be folded in without an OSM
     * reimport, and without compounding the boost every time.
     */
    public function up(): void
    {
        Schema::table('road_segments', function (Blueprint $table) {
            $table->unsignedTinyInteger('base_danger_score')->default(0)->after('danger_score');
        });

        DB::statement('update road_segments set base_danger_score = danger_score');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('road_segments', function (Blueprint $table) {
            $table->dropColumn('base_danger_score');
        });
    }
};
