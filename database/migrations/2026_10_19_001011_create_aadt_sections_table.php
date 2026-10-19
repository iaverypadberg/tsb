<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Vermont AOT publishes only the current year and overwrites it each
     * spring, so the year is part of the key: an import adds a year rather
     * than replacing what is held, and the history the service throws away
     * accumulates here instead.
     */
    public function up(): void
    {
        Schema::create('aadt_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->index();
            $table->unsignedTinyInteger('layer_id');
            $table->unsignedInteger('aadt');
            $table->magellanMultiLineString('geom', 4326);

            // VTrans locates a count on its own linear referencing system:
            // a route id and a milepost range along it. Kept because it is
            // the only stable identity a section has.
            $table->string('ete_id');
            $table->decimal('ete_begin_mm', 8, 3);
            $table->decimal('ete_end_mm', 8, 3);
            $table->decimal('begin_mm', 8, 3)->nullable();
            $table->decimal('end_mm', 8, 3)->nullable();

            $table->string('route_name')->nullable();
            $table->string('route_num')->nullable();
            $table->string('route_type')->nullable()->index();
            $table->string('functional_class')->nullable();
            $table->string('standard_route_code')->nullable();
            $table->string('town_name')->nullable();

            // Which permanent counter the figure came from, if any, and what
            // VTrans thinks of the section's placement. Stored verbatim: the
            // service documents neither, so nothing here filters on them.
            $table->string('atr_station')->nullable();
            $table->string('atr_status')->nullable();
            $table->string('location_error')->nullable();

            $table->boolean('is_divided')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['year', 'ete_id', 'ete_begin_mm', 'ete_end_mm'],
                'aadt_sections_year_section_unique',
            );
        });

        Schema::table('aadt_sections', function (Blueprint $table) {
            $table->spatialIndex('geom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aadt_sections');
    }
};
