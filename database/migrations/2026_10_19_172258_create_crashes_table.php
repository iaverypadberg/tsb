<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per reported crash, as the Vermont crash export publishes it.
     *
     * The export's own `ObjectID` is kept as the natural key so re-importing
     * a file, or importing an overlapping one, refreshes rows in place rather
     * than piling up duplicates.
     *
     * Most columns are held as text exactly as exported. The vocabulary is
     * the crash report form's rather than anything this application defines,
     * and inventing enums for it would mean a migration every time the form
     * changes. What scoring actually needs is the point and the severity.
     *
     * `road_segment_id` is filled in afterwards by `crashes:match`, not at
     * import: a crash is a point, so it belongs to at most one road, and the
     * nearest road can only be found once both are in the database.
     */
    public function up(): void
    {
        Schema::create('crashes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('object_id')->unique();

            // Nullable because a handful of exported rows carry no usable
            // date, and a crash with no location cannot be matched to a road
            // but is still worth holding so the import totals reconcile.
            $table->timestamp('occurred_at')->nullable()->index();
            $table->magellanPoint('geom', 4326)->nullable();

            $table->foreignId('road_segment_id')->nullable()->constrained()->nullOnDelete();

            // How far the crash sat from the road it was matched to, kept so
            // a surprising match can be judged rather than just trusted.
            $table->decimal('match_distance_m', 6, 1)->nullable();

            $table->string('crash_type')->nullable()->index();
            $table->string('involving')->nullable()->index();
            $table->string('town')->nullable();
            $table->string('address')->nullable();
            $table->string('street_address')->nullable();
            $table->string('non_reportable_address')->nullable();
            $table->string('aot_route')->nullable();
            $table->string('aot_route_id')->nullable();
            $table->decimal('aot_milepoint', 8, 2)->nullable();
            $table->string('collision_direction')->nullable();
            $table->string('weather')->nullable();
            $table->string('road_group')->nullable();
            $table->unsignedSmallInteger('road_group_id')->nullable();
            $table->string('report_number')->nullable();
            $table->string('reporting_agency')->nullable();
            $table->string('reporting_agency_id')->nullable();
            $table->string('animal')->nullable();
            $table->string('time_of_day')->nullable();
            $table->string('intersection_with')->nullable();
            $table->string('impairment')->nullable();
            $table->string('road_characteristics')->nullable();
            $table->string('road_condition')->nullable();
            $table->string('surface_condition')->nullable();
            $table->string('mapped')->nullable();

            $table->timestamps();

            $table->spatialIndex('geom');
            $table->index('road_segment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crashes');
    }
};
