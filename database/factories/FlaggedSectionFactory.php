<?php

namespace Database\Factories;

use App\Models\FlaggedSection;
use App\Models\RouteAnalysis;
use Clickbar\Magellan\Data\Geometries\LineString;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlaggedSection>
 */
class FlaggedSectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'route_analysis_id' => RouteAnalysis::factory(),
            'start_fraction' => 0.2,
            'end_fraction' => 0.4,
            'geom' => LineString::make([
                Point::make(-72.58, 44.26, srid: 4326),
                Point::make(-72.57, 44.27, srid: 4326),
            ], 4326),
            'length_m' => 1_400,
            'max_danger' => 80,
            'flagged' => true,
            'reasons' => ['US 2', 'Primary', '55 mph limit', 'No bike lane'],
        ];
    }

    /**
     * A stretch nothing was flagged on: one of the quiet parts of the ride.
     */
    public function unflagged(): static
    {
        return $this->state(fn (array $attributes): array => [
            'flagged' => false,
            'max_danger' => 22,
            'reasons' => ['Hollow Road', 'Residential', '25 mph limit'],
        ]);
    }

    /**
     * Place the section between two fractions of its route.
     */
    public function spanning(float $start, float $end): static
    {
        return $this->state(fn (array $attributes): array => [
            'start_fraction' => $start,
            'end_fraction' => $end,
        ]);
    }
}
