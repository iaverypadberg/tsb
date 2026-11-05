<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * How much traffic a route carries along its length, cut into stretches
     * of one band each.
     *
     * These hang off the route rather than off an analysis: traffic is a
     * property of the roads ridden, not of how brave the rider is, so the
     * same profile serves every risk tolerance and survives a re-analysis.
     * It is what the map can colour before anybody has asked for anything.
     *
     * `aadt` is kept beside the band so a stretch can say the figure it was
     * banded on, and is null where no count has ever been published.
     */
    public function up(): void
    {
        Schema::create('traffic_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->double('start_fraction');
            $table->double('end_fraction');
            $table->magellanLineString('geom', 4326);
            $table->unsignedInteger('length_m')->default(0);
            $table->string('band');
            $table->unsignedInteger('aadt')->nullable();
            $table->timestamps();

            $table->index(['route_id', 'start_fraction']);
        });

        Schema::table('traffic_sections', function (Blueprint $table) {
            $table->spatialIndex('geom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('traffic_sections');
    }
};
