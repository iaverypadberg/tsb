<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Main roads are known by their route number rather than their name: a
     * rider recognises "US 7" long before "Ethan Allen Highway". Without the
     * ref, a flagged section on a numbered highway has nothing to call it.
     */
    public function up(): void
    {
        Schema::table('osm_highways', function (Blueprint $table) {
            $table->text('ref')->nullable();
        });

        Schema::table('road_segments', function (Blueprint $table) {
            $table->string('ref')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('osm_highways', function (Blueprint $table) {
            $table->dropColumn('ref');
        });

        Schema::table('road_segments', function (Blueprint $table) {
            $table->dropColumn('ref');
        });
    }
};
