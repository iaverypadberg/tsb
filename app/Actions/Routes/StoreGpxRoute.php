<?php

namespace App\Actions\Routes;

use App\Jobs\ProfileRouteTraffic;
use App\Models\Route;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Persists an uploaded GPX file as a route.
 *
 * The original file is kept so the export in a later phase can splice detours
 * into it and preserve per-point elevation, which the 2D route line drops.
 *
 * Working out the traffic on it is queued straight away rather than waited
 * for: it is the first thing the route page can show, and it does not depend
 * on anything the rider has chosen yet.
 */
class StoreGpxRoute
{
    public function __construct(private ParseGpxFile $parser) {}

    /**
     * @throws InvalidGpxFile
     */
    public function handle(RouteOwner $owner, UploadedFile $file): Route
    {
        $parsed = $this->parser->handle(
            contents: (string) $file->get(),
            fallbackName: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: __('Untitled route'),
        );

        $path = $file->store($owner->storageDirectory());

        $route = DB::transaction(function () use ($owner, $parsed, $path): Route {
            $route = Route::create([
                ...$owner->attributes(),
                'name' => $parsed->name,
                'source' => Route::SOURCE_GPX,
                'geom' => $parsed->geometry,
                'elevation_gain_m' => $parsed->elevationGainM,
                'point_count' => $parsed->pointCount,
                'original_gpx_path' => $path,
            ]);

            // Measured on the geography type so the result is real metres on
            // the spheroid rather than degrees.
            DB::update(
                'update routes set distance_m = round(ST_Length(geom::geography)) where id = ?',
                [$route->id],
            );

            return $route->refresh();
        });

        $route->update([
            'traffic_status' => Route::TRAFFIC_QUEUED,
            'traffic_queued_at' => now(),
        ]);

        // Marking it queued before handing it over is what stops a second
        // reader queueing the same work twice, but it is a claim the dispatch
        // has still to make good on. If the queue refuses the job the route
        // would otherwise be left saying "queued" for ever with nothing
        // enqueued, so the claim is withdrawn and the route goes back to
        // being one that queues itself the next time its page is opened.
        try {
            ProfileRouteTraffic::dispatch($route);
        } catch (Throwable $e) {
            $route->update([
                'traffic_status' => Route::TRAFFIC_UNPROFILED,
                'traffic_queued_at' => null,
            ]);

            report($e);
        }

        return $route;
    }
}
