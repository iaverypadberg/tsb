<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The pgRouting graph. `cost` is length in metres multiplied by a danger
     * penalty, so a shortest-path search naturally avoids bad roads.
     */
    public function up(): void
    {
        Schema::create('routing_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('road_segment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('source')->index();
            $table->unsignedBigInteger('target')->index();
            $table->double('cost');
            $table->double('reverse_cost');
            $table->double('length_m');
            $table->magellanLineString('geom', 4326);
            $table->timestamps();
        });

        Schema::table('routing_edges', function (Blueprint $table) {
            $table->spatialIndex('geom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routing_edges');
    }
};
