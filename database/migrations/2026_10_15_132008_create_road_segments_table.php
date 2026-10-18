<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('road_segments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('osm_way_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->magellanLineString('geom', 4326);
            $table->string('highway_type')->nullable()->index();
            $table->unsignedSmallInteger('speed_limit_kmh')->nullable();
            $table->unsignedTinyInteger('lanes')->nullable();
            $table->boolean('has_shoulder')->nullable();
            $table->string('bike_infra')->default('none');
            $table->unsignedTinyInteger('danger_score')->default(0)->index();
            $table->string('source')->default('osm');
            $table->timestamps();
        });

        Schema::table('road_segments', function (Blueprint $table) {
            $table->spatialIndex('geom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('road_segments');
    }
};
