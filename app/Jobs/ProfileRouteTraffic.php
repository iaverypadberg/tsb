<?php

namespace App\Jobs;

use App\Actions\Analysis\BuildTrafficRuns;
use App\Actions\Analysis\MatchRouteToRoadSegments;
use App\Actions\Analysis\TrafficRun;
use App\Models\Route;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Works out how much traffic a route carries, stretch by stretch.
 *
 * Dispatched when a route is uploaded rather than when a rider asks for it,
 * because the answer does not depend on anything they choose: the profile is
 * the same for a cautious rider and a brave one, so it can be ready before
 * either of them arrives. A route from before this existed queues itself the
 * first time its page is opened.
 *
 * It is the same spatial matching {@see AnalyseRoute} does, which is a second
 * or two against every road in the region and so too long to make somebody
 * wait on a page load.
 */
class ProfileRouteTraffic implements ShouldQueue
{
    use Queueable;

    /**
     * Matching is deterministic, so a retry only helps against a transient
     * database problem.
     */
    public int $tries = 3;

    public function __construct(public Route $route) {}

    public function handle(MatchRouteToRoadSegments $matcher, BuildTrafficRuns $builder): void
    {
        $runs = $builder->handle(
            samples: $matcher->handle($this->route),
            routeLengthMetres: (float) $this->route->distance_m,
        );

        DB::transaction(function () use ($runs): void {
            $this->route->trafficSections()->delete();

            foreach ($runs as $run) {
                $this->storeSection($run);
            }
        });

        $this->route->update([
            'traffic_status' => Route::TRAFFIC_COMPLETED,
            'traffic_profiled_at' => now(),
        ]);
    }

    /**
     * Record one stretch, cutting its geometry out of the route line so it
     * follows the ridden line exactly instead of approximating it.
     */
    private function storeSection(TrafficRun $run): void
    {
        DB::insert(
            <<<'SQL'
                insert into traffic_sections (
                    route_id, start_fraction, end_fraction, geom,
                    length_m, band, road_name, aadt, speed_limit_kmh,
                    created_at, updated_at
                )
                select ?, ?, ?, ST_LineSubstring(r.geom, ?, ?), ?, ?, ?, ?, ?, now(), now()
                from routes r
                where r.id = ?
            SQL,
            [
                $this->route->id,
                $run->startFraction,
                $run->endFraction,
                $run->startFraction,
                $run->endFraction,
                $run->lengthMetres,
                $run->band->value,
                $run->roadName,
                $run->aadt,
                $run->speedLimitKmh,
                $this->route->id,
            ],
        );
    }

    /**
     * Record that it never finished, so the page can stop saying "working"
     * and say what happened instead.
     */
    public function failed(?Throwable $exception): void
    {
        $this->route->update(['traffic_status' => Route::TRAFFIC_FAILED]);
    }
}
