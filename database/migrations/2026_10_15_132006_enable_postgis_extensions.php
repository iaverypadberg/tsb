<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgrouting');
    }

    /**
     * Reverse the migrations.
     *
     * The extensions are left in place: dropping them would cascade into every
     * geometry column in the database.
     */
    public function down(): void
    {
        //
    }
};
