<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * When the traffic job was handed to the queue, so a page waiting on it
     * can tell work in hand from work that is never coming. Without this
     * there is no clock on the wait: a job that is dropped rather than
     * failed leaves the status on queued, and the page polls every two
     * seconds for as long as the tab is open because nothing in the row
     * says how long it has been saying so.
     *
     * Null on the routes already in the table. Those fall back to
     * `updated_at`, which is when their status was last written and so the
     * nearest thing to a queueing time that the row still remembers.
     */
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->timestamp('traffic_queued_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn('traffic_queued_at');
        });
    }
};
