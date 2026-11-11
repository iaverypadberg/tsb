<?php

namespace App\Console\Commands\Routes;

use App\Actions\Routes\PruneGuestRoutes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class PruneGuestUploads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * There is no window to pass in. Each unclaimed upload carries the date
     * it goes away, and that date was shown to whoever uploaded it, so it is
     * not an operator's to move.
     *
     * @var string
     */
    protected $signature = 'routes:prune-guest-uploads
        {--pretend : Report what would go, and delete nothing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete expired route uploads that no account ever claimed, and their GPX files';

    public function handle(PruneGuestRoutes $pruner): int
    {
        $pretending = (bool) $this->option('pretend');

        $count = $pruner->handle(Date::now(), $pretending);

        if ($count === 0) {
            $this->components->info('No expired uploads to delete.');

            return self::SUCCESS;
        }

        $this->components->info($pretending
            ? "{$count} expired uploads are waiting to be deleted. Nothing was deleted."
            : "Deleted {$count} expired uploads.");

        return self::SUCCESS;
    }
}
