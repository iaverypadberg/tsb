<?php

namespace App\Actions\Routes;

use App\Models\Route;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Clears out uploads whose time is up.
 *
 * Every unclaimed upload carries the date it goes away, which is the date the
 * page showed whoever uploaded it and whoever they sent the link to. This
 * deletes what is past that date; refusing to show it already happened, in
 * the policy, the moment it expired.
 *
 * Routes claimed by an account have no expiry and are never touched.
 */
class PruneGuestRoutes
{
    /**
     * How many rows to take at a time.
     */
    private const CHUNK = 500;

    /**
     * Delete unclaimed uploads that had expired by the given moment.
     *
     * @param  bool  $pretend  Count what would go without deleting it.
     * @return int How many uploads were deleted, or would have been.
     */
    public function handle(CarbonInterface $asOf, bool $pretend = false): int
    {
        $query = Route::query()
            // Belt and braces: a claimed route has no expiry, so the second
            // condition is already enough, but nothing about this command
            // should be one typo away from deleting an account's routes.
            ->whereNull('user_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $asOf);

        if ($pretend) {
            return $query->count();
        }

        $deleted = 0;
        $tokens = [];

        $query->select(['id', 'guest_token', 'original_gpx_path'])
            ->chunkById(self::CHUNK, function ($routes) use (&$deleted, &$tokens): void {
                $paths = $routes->pluck('original_gpx_path')->filter()->all();

                if ($paths !== []) {
                    Storage::delete($paths);
                }

                foreach ($routes->pluck('guest_token')->filter()->unique() as $token) {
                    $tokens[$token] = true;
                }

                // The cascade on route_id takes the traffic sections and
                // analyses with them.
                $deleted += Route::query()->whereIn('id', $routes->pluck('id'))->delete();
            });

        $this->removeEmptyGuestDirectories(array_keys($tokens));

        return $deleted;
    }

    /**
     * Take away the folder of any guest who has nothing left in it.
     *
     * Checked per token rather than assumed, because a guest who uploaded
     * twice may still have the newer of the two.
     *
     * @param  list<string>  $tokens
     */
    private function removeEmptyGuestDirectories(array $tokens): void
    {
        foreach ($tokens as $token) {
            $stillHasRoutes = Route::query()
                ->where('guest_token', $token)
                ->exists();

            if (! $stillHasRoutes) {
                Storage::deleteDirectory(RouteOwner::guest($token)->storageDirectory());
            }
        }
    }
}
