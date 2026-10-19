<?php

namespace Database\Factories;

use App\Models\AadtSection;
use Clickbar\Magellan\Data\Geometries\LineString;
use Clickbar\Magellan\Data\Geometries\MultiLineString;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AadtSection>
 */
class AadtSectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $route = fake()->unique()->numberBetween(1000, 9999);

        return [
            'year' => 2025,
            'layer_id' => AadtSection::LAYER_OTHER,
            'aadt' => fake()->numberBetween(100, 12_000),
            'ete_id' => 'S'.$route,
            'ete_begin_mm' => 0,
            'ete_end_mm' => 1.5,
            'begin_mm' => 0,
            'end_mm' => 1.5,
            'route_name' => fake()->streetName(),
            'route_num' => 'S'.$route,
            'route_type' => 'FAU',
            'functional_class' => '5',
            'standard_route_code' => 'S'.$route,
            'town_name' => 'MONTPELIER',
            'atr_station' => null,
            'atr_status' => null,
            'location_error' => 'NO ERROR',
            'is_divided' => false,
            'extracted_at' => now(),
            ...$this->geometryBetween(44.26, -72.58, 44.27, -72.57),
        ];
    }

    /**
     * A busy road: enough traffic to matter to a rider.
     */
    public function busy(): static
    {
        return $this->state(fn (array $attributes): array => [
            'aadt' => 18_000,
            'route_type' => 'US',
            'functional_class' => '3',
        ]);
    }

    /**
     * An interstate or one of its ramps.
     */
    public function limitedAccess(): static
    {
        return $this->state(fn (array $attributes): array => [
            'layer_id' => AadtSection::LAYER_LIMITED,
            'route_type' => 'I',
            'functional_class' => '1',
            'is_divided' => true,
            'aadt' => 28_000,
        ]);
    }

    /**
     * Place the section on an explicit straight line.
     */
    public function between(float $fromLat, float $fromLon, float $toLat, float $toLon): static
    {
        return $this->state(fn (array $attributes): array => $this->geometryBetween($fromLat, $fromLon, $toLat, $toLon));
    }

    /**
     * Counts are recorded against a year, and a road can be measured in
     * more than one.
     */
    public function year(int $year): static
    {
        return $this->state(fn (array $attributes): array => [
            'year' => $year,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function geometryBetween(float $fromLat, float $fromLon, float $toLat, float $toLon): array
    {
        return [
            'geom' => MultiLineString::make([
                LineString::make([
                    Point::make($fromLon, $fromLat, srid: 4326),
                    Point::make($toLon, $toLat, srid: 4326),
                ], 4326),
            ], 4326),
        ];
    }
}
