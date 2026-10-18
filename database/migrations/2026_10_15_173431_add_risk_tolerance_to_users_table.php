<?php

use App\Models\RouteAnalysis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * How much traffic a rider will put up with is a property of the rider,
     * not of one route, so it belongs on the account and carries over to
     * every analysis they run.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('risk_tolerance')->default(RouteAnalysis::TOLERANCE_NORMAL);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('risk_tolerance');
        });
    }
};
