<?php

namespace App\Actions\Analysis;

use App\Actions\RoadSegments\InterpretOsmHighway;
use App\Actions\RoadSegments\ScoreTrafficVolume;
use App\Models\RoadSegment;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Explains, in a few short phrases, why a stretch of road was flagged.
 *
 * A score on its own tells a rider nothing. "US 2, 55 mph, no bike lane" is
 * something they can judge for themselves, and disagree with.
 */
class DescribeRoadSegments
{
    public function __construct(
        private ScoreTrafficVolume $scoreTrafficVolume,
        private InterpretOsmHighway $interpretOsmHighway,
    ) {}

    /**
     * Lane counts at or above this are worth mentioning.
     */
    private const NOTABLE_LANE_COUNT = 4;

    /**
     * @param  Collection<int, RoadSegment>  $segments
     * @return list<string>
     */
    public function handle(Collection $segments): array
    {
        if ($segments->isEmpty()) {
            return [];
        }

        return array_values(array_filter([
            $this->roadNames($segments),
            $this->roadClass($segments),
            $this->trafficVolume($segments),
            $this->speedLimit($segments),
            $this->laneCount($segments),
            $this->shoulder($segments),
            $this->bikeInfrastructure($segments),
            $this->crashHistory($segments),
            $this->reports($segments),
        ]));
    }

    /**
     * Translate one phrase.
     *
     * The translator is documented as being able to return an array, for a
     * key holding a list, so fall back to the key itself rather than pretend
     * that cannot happen.
     *
     * @param  array<string, mixed>  $replace
     */
    private function line(string $key, array $replace = []): string
    {
        $translated = __($key, $replace);

        return is_string($translated) ? $translated : $key;
    }

    /**
     * @param  Collection<int, RoadSegment>  $segments
     */
    private function roadNames(Collection $segments): ?string
    {
        $names = $segments
            ->map(fn (RoadSegment $segment): ?string => $segment->ref ?: $segment->name)
            ->filter()
            ->unique()
            ->take(3);

        return $names->isEmpty() ? null : $names->join(', ');
    }

    /**
     * @param  Collection<int, RoadSegment>  $segments
     */
    private function roadClass(Collection $segments): ?string
    {
        $worst = $segments->sortByDesc('danger_score')->first();

        return $worst?->highway_type === null
            ? null
            : Str::of($worst->highway_type)->replace('_', ' ')->ucfirst()->toString();
    }

    /**
     * The measured traffic on the busiest part of the stretch.
     *
     * Said in vehicles a day rather than as a score, because it is the one
     * figure here that was counted rather than inferred, and the one a rider
     * can weigh against their own experience of the road.
     *
     * Only roads VTrans has counted have a figure at all; the rest say
     * nothing, which is not a claim that they are quiet.
     *
     * @param  Collection<int, RoadSegment>  $segments
     */
    private function trafficVolume(Collection $segments): ?string
    {
        $busiest = $segments
            ->filter(fn (RoadSegment $segment): bool => $this->scoreTrafficVolume->handle(
                $segment->aadt,
                $segment->bike_infra,
                $segment->highway_type,
            ) !== 0)
            ->max('aadt');

        return $busiest === null
            ? null
            : $this->line(':count vehicles a day', ['count' => Number::format($busiest) ?: (string) $busiest]);
    }

    /**
     * @param  Collection<int, RoadSegment>  $segments
     */
    private function speedLimit(Collection $segments): ?string
    {
        $fastest = $this->interpretOsmHighway->speedLimitMph($segments->max('speed_limit_kmh'));

        return $fastest === null ? null : $this->line(':speed mph limit', ['speed' => $fastest]);
    }

    /**
     * @param  Collection<int, RoadSegment>  $segments
     */
    private function laneCount(Collection $segments): ?string
    {
        $widest = $segments->max('lanes');

        return $widest >= self::NOTABLE_LANE_COUNT
            ? $this->line(':count lanes', ['count' => $widest])
            : null;
    }

    /**
     * Only said when the road is actually tagged as having none: almost no
     * road carries the tag, and silence is not the same as absence.
     *
     * @param  Collection<int, RoadSegment>  $segments
     */
    private function shoulder(Collection $segments): ?string
    {
        return $segments->contains(fn (RoadSegment $segment): bool => $segment->has_shoulder === false)
            ? $this->line('No shoulder')
            : null;
    }

    /**
     * @param  Collection<int, RoadSegment>  $segments
     */
    private function bikeInfrastructure(Collection $segments): ?string
    {
        return $segments->every(fn (RoadSegment $segment): bool => $segment->bike_infra === RoadSegment::INFRA_NONE)
            ? $this->line('No bike lane')
            : null;
    }

    /**
     * What has actually happened on this stretch.
     *
     * Said as a count rather than as a rate, because a rate is what the
     * score is built from but a count is what a rider recognises. Injuries
     * are named separately: "four crashes" and "four crashes, two with
     * injuries" are not the same stretch of road.
     *
     * @param  Collection<int, RoadSegment>  $segments
     */
    private function crashHistory(Collection $segments): ?string
    {
        $crashes = $segments->sum(fn (RoadSegment $segment): int => $segment->crash_count);

        if ($crashes === 0) {
            return null;
        }

        $injuries = $segments->sum(
            fn (RoadSegment $segment): int => $segment->crash_injury_count,
        );

        $counted = trans_choice('{1}:count recorded crash|[2,*]:count recorded crashes', $crashes, [
            'count' => $crashes,
        ]);

        return $injuries === 0
            ? $counted
            : $counted.' '.$this->line('(:count with injuries)', ['count' => $injuries]);
    }

    /**
     * @param  Collection<int, RoadSegment>  $segments
     */
    private function reports(Collection $segments): ?string
    {
        $total = $segments->sum(fn (RoadSegment $segment): int => $segment->reports_count ?? 0);

        return $total > 0
            ? trans_choice('{1}:count report|[2,*]:count reports', $total, ['count' => $total])
            : null;
    }
}
