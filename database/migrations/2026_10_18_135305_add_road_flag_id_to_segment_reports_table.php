<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Accepting a rider's flag writes the report it earned. The link back
     * keeps that one-way: withdrawing or rejecting the flag takes the report
     * with it, and nothing a rider submits touches a danger score until
     * someone has read it.
     */
    public function up(): void
    {
        Schema::table('segment_reports', function (Blueprint $table) {
            $table->foreignId('road_flag_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('segment_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('road_flag_id');
        });
    }
};
