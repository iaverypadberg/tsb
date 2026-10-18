<?php

namespace App\Actions\RoadSegments;

use App\Models\RoadSegment;

/**
 * Turns the raw OpenStreetMap tags of one way into the fields `road_segments`
 * stores, including a 0-100 danger score for cycling.
 *
 * The score is deliberately a transparent sum rather than a trained model: a
 * rider should be able to read "60 mph, four lanes, no bike lane" and see why
 * a road was flagged.
 *
 * What makes a road dangerous is the traffic on it, and OpenStreetMap does
 * not record traffic volume. Road class is only a loose proxy: in rural
 * Vermont a `tertiary` road is usually a quiet lane, not an arterial. Two
 * better covered signals carry most of the discrimination here:
 *
 *  - a route number (`ref`) means through traffic. Primary and secondary ways
 *    carry one 95% and 84% of the time; tertiary and unclassified only 4% and
 *    2%. So the number, not the class, is what marks a through route.
 *  - an unpaved surface means a quiet road. It is tagged on 41% of ways,
 *    against 5% for speed limits, and no primary road in the state is
 *    unpaved while 56% of unclassified ways are.
 *
 * Every weight is still a judgement call, and traffic counts from Vermont AOT
 * would beat all of this.
 */
class InterpretOsmHighway
{
    /**
     * Baseline danger by road class, before speed, lanes and bike provision.
     *
     * Motorways score at the ceiling because riding one is not an option, so
     * routing must treat them as impassable rather than merely unpleasant.
     *
     * @var array<string, int>
     */
    private const BASE_SCORES = [
        'motorway' => 100,
        'motorway_link' => 95,
        'trunk' => 77,
        'trunk_link' => 72,
        'primary' => 62,
        'primary_link' => 57,
        'secondary' => 47,
        'secondary_link' => 42,
        'tertiary' => 30,
        'tertiary_link' => 28,
        'unclassified' => 20,
        'residential' => 15,
        'service' => 10,
        'track' => 10,
        'bridleway' => 8,
        'living_street' => 8,
        'path' => 5,
        'cycleway' => 0,
    ];

    /**
     * Used when a way carries a highway value we have no baseline for.
     */
    private const UNKNOWN_BASE_SCORE = 25;

    /**
     * Added when a way carries a route number, which means it is a road
     * through somewhere rather than a road to somewhere. The class baselines
     * above are set assuming a numbered primary or secondary will collect
     * this, so a numbered road lands roughly where it always did and an
     * unnumbered one gets the benefit of the doubt.
     */
    private const THROUGH_ROUTE_PENALTY = 8;

    /**
     * Taken off an unpaved road, which carries little traffic and slowly.
     *
     * This is about traffic, not comfort: gravel is harder work to ride but
     * far less likely to have a lorry on it.
     */
    private const UNPAVED_CREDIT = 12;

    /**
     * Surfaces that mean the road is not sealed.
     *
     * @var list<string>
     */
    private const UNPAVED_SURFACES = [
        'unpaved', 'gravel', 'fine_gravel', 'dirt', 'ground', 'earth',
        'compacted', 'sand', 'grass', 'mud', 'woodchips', 'pebblestone',
    ];

    /**
     * Ways already scored as near harmless, where the unpaved credit would
     * buy nothing and would only make a farm track look like an inviting
     * detour to the router in a later phase.
     *
     * @var list<string>
     */
    private const NOT_ELIGIBLE_FOR_UNPAVED_CREDIT = [
        'track', 'path', 'bridleway', 'cycleway', 'service', 'living_street',
    ];

    /**
     * How much each kind of cycling provision takes off the score.
     *
     * @var array<string, int>
     */
    private const INFRA_CREDITS = [
        RoadSegment::INFRA_PATH => 35,
        RoadSegment::INFRA_PROTECTED => 30,
        RoadSegment::INFRA_BUFFERED => 18,
        RoadSegment::INFRA_PAINTED => 10,
        RoadSegment::INFRA_NONE => 0,
    ];

    /**
     * Cycleway tag values that mean physical separation from traffic.
     *
     * @var list<string>
     */
    private const PROTECTED_VALUES = ['track', 'opposite_track', 'separate', 'sidepath'];

    /**
     * @var list<string>
     */
    private const BUFFERED_VALUES = ['buffered_lane'];

    /**
     * @var list<string>
     */
    private const PAINTED_VALUES = ['lane', 'opposite_lane', 'shared_lane', 'share_busway', 'opposite'];

    /**
     * Interpret one staged OpenStreetMap way.
     *
     * @param  array<string, string|null>  $tags
     * @return array{speed_limit_kmh: int|null, lanes: int|null, has_shoulder: bool|null, bike_infra: string, danger_score: int}
     */
    public function handle(array $tags): array
    {
        $highway = (string) ($tags['highway'] ?? '');
        $speedLimit = $this->speedLimitKmh($tags['maxspeed'] ?? null);
        $lanes = $this->lanes($tags['lanes'] ?? null);
        $hasShoulder = $this->hasShoulder($tags['shoulder'] ?? null);
        $bikeInfra = $this->bikeInfra($tags);

        return [
            'speed_limit_kmh' => $speedLimit,
            'lanes' => $lanes,
            'has_shoulder' => $hasShoulder,
            'bike_infra' => $bikeInfra,
            'danger_score' => $this->dangerScore(
                highway: $highway,
                speedLimitKmh: $speedLimit,
                lanes: $lanes,
                hasShoulder: $hasShoulder,
                bikeInfra: $bikeInfra,
                isThroughRoute: $this->isThroughRoute($tags['ref'] ?? null),
                isUnpaved: $this->isUnpaved($tags['surface'] ?? null),
            ),
        ];
    }

    /**
     * Speed limits in the United States are tagged in miles per hour, so the
     * unit matters: reading "65 mph" as 65 km/h would understate the road
     * badly.
     */
    public function speedLimitKmh(?string $maxspeed): ?int
    {
        $maxspeed = trim((string) $maxspeed);

        if ($maxspeed === '' || ! preg_match('/^(\d+(?:\.\d+)?)\s*(mph|km\/h|kmh|kph)?$/i', $maxspeed, $matches)) {
            return null;
        }

        $value = (float) $matches[1];
        $unit = strtolower($matches[2] ?? '');

        $kmh = $unit === 'mph' ? $value * 1.609344 : $value;

        return (int) round($kmh);
    }

    private function lanes(?string $lanes): ?int
    {
        // Values such as "2;3" appear on ways that change lane count.
        if (! is_string($lanes) || ! preg_match('/^\d+/', trim($lanes), $matches)) {
            return null;
        }

        $count = (int) $matches[0];

        return $count > 0 ? $count : null;
    }

    /**
     * Left as null when the tag is absent, which is almost always: an
     * untagged road is unknown, not shoulderless, and should not be
     * penalised as though we knew.
     */
    private function hasShoulder(?string $shoulder): ?bool
    {
        return match (strtolower(trim((string) $shoulder))) {
            'yes', 'both', 'left', 'right' => true,
            'no', 'none' => false,
            default => null,
        };
    }

    /**
     * @param  array<string, string|null>  $tags
     */
    public function bikeInfra(array $tags): string
    {
        $highway = strtolower(trim((string) ($tags['highway'] ?? '')));

        if ($highway === 'cycleway') {
            return RoadSegment::INFRA_PATH;
        }

        if (in_array($highway, ['path', 'bridleway'], true)
            && in_array(strtolower(trim((string) ($tags['bicycle'] ?? ''))), ['designated', 'yes'], true)) {
            return RoadSegment::INFRA_PATH;
        }

        $values = [];

        foreach (['cycleway', 'cycleway_both', 'cycleway_left', 'cycleway_right'] as $key) {
            $value = strtolower(trim((string) ($tags[$key] ?? '')));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        // Take the best provision present on either side of the road.
        if (array_intersect($values, self::PROTECTED_VALUES) !== []) {
            return RoadSegment::INFRA_PROTECTED;
        }

        if (array_intersect($values, self::BUFFERED_VALUES) !== []) {
            return RoadSegment::INFRA_BUFFERED;
        }

        if (array_intersect($values, self::PAINTED_VALUES) !== []) {
            return RoadSegment::INFRA_PAINTED;
        }

        return RoadSegment::INFRA_NONE;
    }

    /**
     * Combine the factors into a 0-100 score.
     */
    public function dangerScore(
        string $highway,
        ?int $speedLimitKmh,
        ?int $lanes,
        ?bool $hasShoulder,
        string $bikeInfra,
        bool $isThroughRoute = false,
        bool $isUnpaved = false,
    ): int {
        $score = self::BASE_SCORES[$highway] ?? self::UNKNOWN_BASE_SCORE;

        $score += $this->speedPenalty($speedLimitKmh);
        $score += $this->lanePenalty($lanes);

        if ($isThroughRoute) {
            $score += self::THROUGH_ROUTE_PENALTY;
        }

        if ($isUnpaved && ! in_array($highway, self::NOT_ELIGIBLE_FOR_UNPAVED_CREDIT, true)) {
            $score -= self::UNPAVED_CREDIT;
        }

        if ($hasShoulder === true) {
            $score -= 12;
        } elseif ($hasShoulder === false) {
            $score += 5;
        }

        $score -= self::INFRA_CREDITS[$bikeInfra] ?? 0;

        return max(0, min(RoadSegment::MAX_DANGER, $score));
    }

    /**
     * Whether the way carries a route number, and so through traffic.
     */
    public function isThroughRoute(?string $ref): bool
    {
        return trim((string) $ref) !== '';
    }

    /**
     * Whether the way is unsealed.
     */
    public function isUnpaved(?string $surface): bool
    {
        return in_array(strtolower(trim((string) $surface)), self::UNPAVED_SURFACES, true);
    }

    /**
     * Thresholds sit on the km/h equivalents of the common US limits:
     * 35, 45 and 55 mph.
     */
    private function speedPenalty(?int $speedLimitKmh): int
    {
        return match (true) {
            $speedLimitKmh === null => 0,
            $speedLimitKmh <= 40 => 0,
            $speedLimitKmh <= 56 => 8,
            $speedLimitKmh <= 72 => 16,
            $speedLimitKmh <= 88 => 24,
            default => 32,
        };
    }

    private function lanePenalty(?int $lanes): int
    {
        return match (true) {
            $lanes === null => 0,
            $lanes >= 4 => 12,
            $lanes === 3 => 6,
            default => 0,
        };
    }
}
