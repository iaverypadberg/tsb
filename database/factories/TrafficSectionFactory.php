<?php

namespace Database\Factories;

use App\Actions\Aadt\TrafficBand;
use App\Models\Route;
use App\Models\TrafficSection;
use Clickbar\Magellan\Data\Geometries\LineString;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficSection>
 */
class TrafficSectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'route_id' => Route::factory(),
            'start_fraction' => 0.0,
            'end_fraction' => 1.0,
            'geom' => LineString::make([
                Point::make(-72.58, 44.26, srid: 4326),
                Point::make(-72.57, 44.27, srid: 4326),
            ], 4326),
            'length_m' => 2_400,
            'band' => TrafficBand::Moderate,
            'road_name' => 'VT 12',
            'aadt' => 2_500,
            // Untagged by default, because that is nineteen Vermont ways in
            // twenty. A test that cares about the limit says so.
            'speed_limit_kmh' => null,
        ];
    }

    /**
     * A stretch posted at a given limit.
     */
    public function postedAt(int $speedLimitKmh): static
    {
        return $this->state(['speed_limit_kmh' => $speedLimitKmh]);
    }

    /**
     * A stretch carrying a given count, banded the way the map bands it.
     */
    public function carrying(int $aadt): static
    {
        return $this->state(fn (array $attributes): array => [
            'band' => TrafficBand::for($aadt),
            'aadt' => $aadt,
        ]);
    }

    /**
     * A stretch on roads nobody has ever counted, which is most of them.
     */
    public function notCounted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'band' => TrafficBand::NotCounted,
            'aadt' => null,
        ]);
    }

    /**
     * A stretch on a road nobody has named, which is most of the lanes here.
     */
    public function unnamed(): static
    {
        return $this->state(['road_name' => null]);
    }

    /**
     * Place the stretch on an explicit straight line, for the tests that
     * care where it is rather than only how busy it is.
     */
    public function between(float $fromLat, float $fromLon, float $toLat, float $toLon): static
    {
        return $this->state(fn (array $attributes): array => [
            'geom' => LineString::make([
                Point::make($fromLon, $fromLat, srid: 4326),
                Point::make($toLon, $toLat, srid: 4326),
            ], 4326),
        ]);
    }

    /**
     * Place the stretch between two fractions of its route.
     */
    public function spanning(float $start, float $end): static
    {
        return $this->state(fn (array $attributes): array => [
            'start_fraction' => $start,
            'end_fraction' => $end,
        ]);
    }
}
