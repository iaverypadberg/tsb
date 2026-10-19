<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The matched count is denormalised onto the road so scoring can read it
     * without a join, and `aadt_section_road_segment` keeps the working out:
     * which published sections fed a road, and over how much of it. That is
     * what makes a surprising score answerable, and it lets the figure be
     * recomputed without matching the whole state again.
     *
     * `osm:build-segments` leaves these columns alone on an upsert, so an
     * extract refresh keeps the counts. It does replace geometry though, so
     * `aadt:match` should be re-run after an import.
     */
    public function up(): void
    {
        Schema::table('road_segments', function (Blueprint $table) {
            $table->unsignedInteger('aadt')->nullable()->index();
            $table->unsignedSmallInteger('aadt_year')->nullable();

            // What share of the road's samples found a count, as a stand-in
            // for how much of it was measured rather than inferred.
            $table->decimal('aadt_sample_share', 4, 3)->nullable();
        });

        Schema::create('aadt_section_road_segment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aadt_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('road_segment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('samples');
            $table->timestamps();

            $table->unique(['aadt_section_id', 'road_segment_id'], 'aadt_section_road_segment_unique');
            $table->index('road_segment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aadt_section_road_segment');

        Schema::table('road_segments', function (Blueprint $table) {
            $table->dropColumn(['aadt', 'aadt_year', 'aadt_sample_share']);
        });
    }
};
