<?php

use App\Models\Route;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Whether this route's traffic has been profiled yet, so the page can
     * say "working" rather than "no traffic here" while the job runs, and
     * can tell a route nobody has asked about from one whose profile failed.
     *
     * Existing routes default to unprofiled, which is what makes them queue
     * themselves the first time somebody opens them.
     */
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->string('traffic_status')->default(Route::TRAFFIC_UNPROFILED);
            $table->timestamp('traffic_profiled_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn(['traffic_status', 'traffic_profiled_at']);
        });
    }
};
