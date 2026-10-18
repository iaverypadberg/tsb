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
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('source')->default('gpx');
            $table->string('source_id')->nullable();
            $table->magellanLineString('geom', 4326);
            $table->unsignedInteger('distance_m')->default(0);
            $table->unsignedInteger('elevation_gain_m')->default(0);
            $table->unsignedInteger('point_count')->default(0);
            $table->string('original_gpx_path')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['source', 'source_id']);
        });

        Schema::table('routes', function (Blueprint $table) {
            $table->spatialIndex('geom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
