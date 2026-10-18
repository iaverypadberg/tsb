<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Surface is tagged on 41% of Vermont's ways, against 5% for speed
     * limits, and an unpaved road is almost never a busy one. It is the best
     * covered signal available for telling a quiet back road from a through
     * route, so it is worth carrying onto the segment.
     */
    public function up(): void
    {
        Schema::table('road_segments', function (Blueprint $table) {
            $table->string('surface')->nullable()->after('highway_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('road_segments', function (Blueprint $table) {
            $table->dropColumn('surface');
        });
    }
};
