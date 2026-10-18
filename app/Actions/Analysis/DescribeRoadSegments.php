<?php

namespace App\Actions\Analysis;

use App\Models\RoadSegment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Explains, in a few short phrases, why a stretch of road was flagged.
 *
 * A score on its own tells a rider nothing. "US 2, 89 km/h, no bike lane" is
 * something they can judge for themselves, and disagree with.
 */
class DescribeRoadSegments
{
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
            $this->speedLimit($segments),
            $this->laneCount($segments),
            $this->shoulder($segments),
            $this->bikeInfrastructure($segments),
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
     * @param  Collection<int, RoadSegment>  $segments
     */
    private function speedLimit(Collection $segments): ?string
    {
        $fastest = $segments->max('speed_limit_kmh');

        return $fastest === null ? null : $this->line(':speed km/h limit', ['speed' => $fastest]);
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
