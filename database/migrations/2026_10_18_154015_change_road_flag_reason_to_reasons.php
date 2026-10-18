<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One road is usually several things at once: no shoulder *and* fast
     * traffic *and* a blind bend. Making a rider pick the worst of them
     * throws away what the rest of the report is about, so a flag now
     * carries every reason it was raised for, the way `flagged_sections`
     * already does.
     */
    public function up(): void
    {
        Schema::table('road_flags', function (Blueprint $table) {
            $table->json('reasons')->nullable()->after('user_id');
        });

        DB::statement('update road_flags set reasons = to_jsonb(array[reason]) where reason is not null');

        Schema::table('road_flags', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('road_flags', function (Blueprint $table) {
            $table->string('reason')->nullable()->after('user_id');
        });

        DB::statement('update road_flags set reason = reasons->>0');

        Schema::table('road_flags', function (Blueprint $table) {
            $table->dropColumn('reasons');
        });
    }
};
