<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a route be uploaded by someone who has not signed up.
 *
 * A route then belongs either to a user or to a browser session, never to
 * both and never to neither: `guest_token` is the random value held in the
 * visitor's session, and it is cleared the moment the upload is claimed by
 * an account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();

            $table->string('guest_token', 64)->nullable()->after('user_id');

            $table->index(['guest_token', 'created_at']);
        });

        DB::statement(<<<'SQL'
            alter table routes add constraint routes_one_owner_check check (
                (user_id is not null and guest_token is null)
                or (user_id is null and guest_token is not null)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('alter table routes drop constraint routes_one_owner_check');

        // Nothing owns an unclaimed upload once guests cannot have routes.
        DB::table('routes')->whereNull('user_id')->delete();

        Schema::table('routes', function (Blueprint $table): void {
            $table->dropIndex(['guest_token', 'created_at']);
            $table->dropColumn('guest_token');

            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
