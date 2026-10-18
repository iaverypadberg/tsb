<?php

use App\Models\RoadFlag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A flag is one rider saying one thing about one road: "this bridge has
     * no shoulder". The road they mean can be a whole named road, which is
     * many OpenStreetMap ways, or part of a single way, so the extent lives
     * in a second table rather than in a column here.
     */
    public function up(): void
    {
        Schema::create('road_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason');
            $table->unsignedTinyInteger('severity')->default(3);
            $table->text('notes');
            $table->string('status')->default(RoadFlag::STATUS_PENDING);
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedInteger('length_m')->default(0);
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        /**
         * `start_fraction` / `end_fraction` locate the flagged part along the
         * road segment's line, exactly as `flagged_sections` does along a
         * route. A whole-road flag stores 0 to 1 for every way it covers.
         */
        Schema::create('road_flag_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('road_flag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('road_segment_id')->constrained()->cascadeOnDelete();
            $table->double('start_fraction')->default(0);
            $table->double('end_fraction')->default(1);
            $table->magellanLineString('geom', 4326);
            $table->unsignedInteger('length_m')->default(0);

            $table->index(['road_flag_id', 'start_fraction']);
            $table->index('road_segment_id');
        });

        Schema::table('road_flag_segments', function (Blueprint $table) {
            $table->spatialIndex('geom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('road_flag_segments');
        Schema::dropIfExists('road_flags');
    }
};
