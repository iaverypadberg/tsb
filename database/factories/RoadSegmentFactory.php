<?php

namespace Database\Factories;

use App\Models\RoadSegment;
use Clickbar\Magellan\Data\Geometries\LineString;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadSegment>
 */
class RoadSegmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'osm_way_id' => fake()->unique()->numberBetween(1, 999_999_999),
            'name' => fake()->streetName(),
            'ref' => null,
            'highway_type' => 'residential',
            'speed_limit_kmh' => 40,
            'lanes' => 2,
            'has_shoulder' => null,
            'bike_infra' => RoadSegment::INFRA_NONE,
            'danger_score' => 18,
            'base_danger_score' => 18,
            'source' => 'osm',
            ...$this->geometryBetween(44.26, -72.58, 44.27, -72.57),
        ];
    }

    /**
     * A busy road a rider would rather avoid.
     */
    public function dangerous(): static
    {
        return $this->state(fn (array $attributes): array => [
            'highway_type' => 'trunk',
            'speed_limit_kmh' => 89,
            'lanes' => 4,
            'danger_score' => 92,
            'base_danger_score' => 92,
        ]);
    }

    /**
     * A separated bike path.
     */
    public function cyclePath(): static
    {
        return $this->state(fn (array $attributes): array => [
            'highway_type' => 'cycleway',
            'speed_limit_kmh' => null,
            'lanes' => null,
            'bike_infra' => RoadSegment::INFRA_PATH,
            'danger_score' => 0,
            'base_danger_score' => 0,
        ]);
    }

    /**
     * Place the segment on an explicit straight line.
     */
    public function between(float $fromLat, float $fromLon, float $toLat, float $toLon): static
    {
        return $this->state(fn (array $attributes): array => $this->geometryBetween($fromLat, $fromLon, $toLat, $toLon));
    }

    /**
     * A segment running due north for roughly a given number of metres.
     *
     * Crash density is a count over a length, so a test about it needs a
     * road whose length it chose. A degree of latitude is close enough to
     * constant for this to land within a metre or so of what was asked.
     */
    public function straight(float $metres): static
    {
        $delta = $metres / 111_320;

        return $this->between(44.26, -72.58, 44.26 + $delta, -72.58);
    }

    /**
     * @return array<string, mixed>
     */
    private function geometryBetween(float $fromLat, float $fromLon, float $toLat, float $toLon): array
    {
        return [
            'geom' => LineString::make([
                Point::make($fromLon, $fromLat, srid: 4326),
                Point::make($toLon, $toLat, srid: 4326),
            ], 4326),
        ];
    }
}
