<?php

namespace App\Jobs;

use App\Actions\Analysis\BuildFlaggedRuns;
use App\Actions\Analysis\DescribeRoadSegments;
use App\Actions\Analysis\FlaggedRun;
use App\Actions\Analysis\MatchRouteToRoadSegments;
use App\Actions\Analysis\RouteSample;
use App\Models\RoadSegment;
use App\Models\RouteAnalysis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Matches a route against the road network and records what it is made of.
 *
 * The dangerous stretches are what the rider is warned about, but they are
 * recorded alongside the stretches in between, so the route can be read as a
 * sequence of its parts rather than as a handful of warnings.
 *
 * Queued because matching a long route is a second or two of database work,
 * which is too long to make someone wait on a page load.
 */
class AnalyseRoute implements ShouldQueue
{
    use Queueable;

    /**
     * Matching is deterministic, so a retry only helps against a transient
     * database problem.
     */
    public int $tries = 3;

    public function __construct(public RouteAnalysis $analysis) {}

    public function handle(
        MatchRouteToRoadSegments $matcher,
        BuildFlaggedRuns $builder,
        DescribeRoadSegments $describe,
    ): void {
        $this->analysis->update([
            'status' => RouteAnalysis::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $route = $this->analysis->route;

        $samples = $matcher->handle($route);

        $runs = $builder->handle(
            samples: $samples,
            threshold: $this->analysis->threshold(),
            routeLengthMetres: (float) $route->distance_m,
        );

        $unflagged = $builder->unflaggedRuns(
            samples: $samples,
            flagged: $runs,
            routeLengthMetres: (float) $route->distance_m,
        );

        DB::transaction(function () use ($runs, $unflagged, $describe): void {
            $this->analysis->sections()->delete();

            foreach ([...$runs, ...$unflagged] as $run) {
                $this->storeSection($run, $describe);
            }
        });

        $this->analysis->update([
            'status' => RouteAnalysis::STATUS_COMPLETED,
            'finished_at' => now(),
            'summary' => $this->summarise($runs, $samples),
        ]);
    }

    /**
     * Record one section, cutting its geometry out of the route line.
     */
    private function storeSection(FlaggedRun $run, DescribeRoadSegments $describe): void
    {
        $segments = RoadSegment::query()
            ->withCount('reports')
            ->whereIn('id', $run->roadSegmentIds)
            ->get();

        // ST_LineSubstring cuts the section straight out of the stored route,
        // so it follows the ridden line exactly instead of approximating it.
        $created = DB::selectOne(
            <<<'SQL'
                insert into flagged_sections (
                    route_analysis_id, start_fraction, end_fraction, geom,
                    length_m, max_danger, flagged, reasons, created_at, updated_at
                )
                select ?, ?, ?, ST_LineSubstring(r.geom, ?, ?), ?, ?, ?, ?::json, now(), now()
                from routes r
                where r.id = ?
                returning id
            SQL,
            [
                $this->analysis->id,
                $run->startFraction,
                $run->endFraction,
                $run->startFraction,
                $run->endFraction,
                $run->lengthMetres,
                $run->maxDanger,
                $run->flagged,
                json_encode($describe->handle($segments)),
                $this->analysis->route_id,
            ],
        );

        if ($created === null || $run->roadSegmentIds === []) {
            return;
        }

        DB::table('flagged_section_road_segment')->insert(array_map(
            fn (int $segmentId): array => [
                'flagged_section_id' => $created->id,
                'road_segment_id' => $segmentId,
            ],
            $run->roadSegmentIds,
        ));
    }

    /**
     * What the analysis found, counted over the flagged runs only: the page
     * reads this to say how much of the route the rider was warned about.
     *
     * @param  list<FlaggedRun>  $runs
     * @param  list<RouteSample>  $samples
     * @return array<string, mixed>
     */
    private function summarise(array $runs, array $samples): array
    {
        $matched = array_filter($samples, fn ($sample): bool => $sample->roadSegmentId !== null);

        return [
            'sections' => count($runs),
            'flagged_m' => array_sum(array_map(fn (FlaggedRun $run): int => $run->lengthMetres, $runs)),
            'max_danger' => $runs === [] ? 0 : max(array_map(fn (FlaggedRun $run): int => $run->maxDanger, $runs)),
            'samples' => count($samples),
            'matched_samples' => count($matched),
        ];
    }

    /**
     * Record why the analysis never finished, so the page can say so.
     */
    public function failed(?Throwable $exception): void
    {
        $this->analysis->update([
            'status' => RouteAnalysis::STATUS_FAILED,
            'finished_at' => now(),
            'failure_reason' => $exception?->getMessage(),
        ]);
    }
}
