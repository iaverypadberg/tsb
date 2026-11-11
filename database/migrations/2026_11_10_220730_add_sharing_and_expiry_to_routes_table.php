<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a route be shared by link, and gives an unclaimed one a lifespan.
 *
 * `shared_at` is the whole of sharing: the public id in the URL is already
 * unguessable, so a shared route needs no second address, and withdrawing
 * the share is a matter of clearing the column.
 *
 * `expires_at` is what an upload nobody has claimed is counting down to. It
 * is set when a guest uploads, cleared when an account claims the route, and
 * it is the one place that decides when a route goes away — both the page
 * that refuses to show an expired route and the command that deletes it read
 * this and nothing else.
 */
return new class extends Migration
{
    /**
     * How long an unclaimed upload lives, in days.
     *
     * Repeated from the model rather than read from it: a migration has to
     * keep saying what it said on the day it ran.
     */
    private const GUEST_LIFETIME_DAYS = 7;

    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->timestamp('shared_at')->nullable()->after('guest_token');
            $table->timestamp('expires_at')->nullable()->after('shared_at');
        });

        // Uploads that predate the column have been living on the pruner's
        // fixed window, so they expire where that window would have put them.
        DB::statement(
            'update routes set expires_at = created_at + make_interval(days => ?) where user_id is null',
            [self::GUEST_LIFETIME_DAYS],
        );

        $table = 'routes';

        DB::statement("alter table {$table} drop constraint routes_one_owner_check");

        // A claimed route carrying an expiry would be deleted out from under
        // the account that claimed it, so the two are tied together here
        // rather than left to the code that writes them.
        DB::statement(<<<SQL
            alter table {$table} add constraint routes_owner_check check (
                (user_id is not null and guest_token is null and expires_at is null)
                or (user_id is null and guest_token is not null and expires_at is not null)
            )
        SQL);

        Schema::table('routes', function (Blueprint $table): void {
            $table->index('expires_at');
            $table->index('shared_at');
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->dropIndex(['shared_at']);
            $table->dropIndex(['expires_at']);
        });

        DB::statement('alter table routes drop constraint routes_owner_check');

        DB::statement(<<<'SQL'
            alter table routes add constraint routes_one_owner_check check (
                (user_id is not null and guest_token is null)
                or (user_id is null and guest_token is not null)
            )
        SQL);

        Schema::table('routes', function (Blueprint $table): void {
            $table->dropColumn(['shared_at', 'expires_at']);
        });
    }
};
