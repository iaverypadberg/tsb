<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Edges are written before the graph has nodes: pgr_createTopology fills
     * `source` and `target` in afterwards by matching endpoints, so they have
     * to be allowed to start empty. `danger_score` rides along so the cost
     * can be recomputed without rejoining to road_segments.
     */
    public function up(): void
    {
        Schema::table('routing_edges', function (Blueprint $table) {
            $table->unsignedBigInteger('source')->nullable()->change();
            $table->unsignedBigInteger('target')->nullable()->change();
            $table->unsignedTinyInteger('danger_score')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routing_edges', function (Blueprint $table) {
            $table->dropColumn('danger_score');
            $table->unsignedBigInteger('source')->nullable(false)->change();
            $table->unsignedBigInteger('target')->nullable(false)->change();
        });
    }
};
