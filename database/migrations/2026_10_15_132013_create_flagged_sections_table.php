<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `start_fraction` / `end_fraction` locate the section along the parent
     * route line, so it can be spliced with ST_LineSubstring on export.
     */
    public function up(): void
    {
        Schema::create('flagged_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_analysis_id')->constrained()->cascadeOnDelete();
            $table->double('start_fraction');
            $table->double('end_fraction');
            $table->magellanLineString('geom', 4326);
            $table->unsignedInteger('length_m')->default(0);
            $table->unsignedTinyInteger('max_danger')->default(0);
            $table->json('reasons')->nullable();
            $table->timestamps();

            $table->index(['route_analysis_id', 'start_fraction']);
        });

        Schema::table('flagged_sections', function (Blueprint $table) {
            $table->spatialIndex('geom');
        });

        Schema::create('flagged_section_road_segment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flagged_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('road_segment_id')->constrained()->cascadeOnDelete();

            $table->unique(['flagged_section_id', 'road_segment_id'], 'flagged_section_segment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flagged_section_road_segment');
        Schema::dropIfExists('flagged_sections');
    }
};
