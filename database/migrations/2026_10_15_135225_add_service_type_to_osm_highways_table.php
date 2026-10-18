<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Over half of a typical extract is `highway=service`, most of it
     * driveways and parking aisles that no one rides. Keeping the `service`
     * subtype lets those be filtered out without re-importing.
     */
    public function up(): void
    {
        Schema::table('osm_highways', function (Blueprint $table) {
            $table->text('service_type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('osm_highways', function (Blueprint $table) {
            $table->dropColumn('service_type');
        });
    }
};
