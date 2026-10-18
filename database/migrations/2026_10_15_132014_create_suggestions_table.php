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
        Schema::create('suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flagged_section_id')->constrained()->cascadeOnDelete();
            $table->magellanLineString('geom', 4326);
            $table->integer('added_distance_m')->default(0);
            $table->integer('added_elevation_m')->default(0);
            $table->unsignedTinyInteger('danger_reduction')->default(0);
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['flagged_section_id', 'status']);
        });

        Schema::table('suggestions', function (Blueprint $table) {
            $table->spatialIndex('geom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggestions');
    }
};
