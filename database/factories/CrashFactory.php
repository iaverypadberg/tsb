<?php

namespace Database\Factories;

use App\Models\Crash;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Crash>
 */
class CrashFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'object_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'occurred_at' => fake()->dateTimeBetween('-5 years'),
            'geom' => Point::makeGeodetic(44.26, -72.58),
            'crash_type' => Crash::TYPE_PROPERTY_DAMAGE,
            'involving' => 'None',
            'town' => 'Montpelier',
            'address' => fake()->streetName(),
            'street_address' => fake()->streetAddress(),
            'aot_route' => 'US-2',
            'aot_route_id' => '0020',
            'aot_milepoint' => fake()->randomFloat(2, 0, 50),
            'collision_direction' => 'Single Vehicle Crash',
            'weather' => 'Clear',
            'road_group' => 'State System (State Highways and Class I TH links)',
            'road_group_id' => 1,
            'report_number' => (string) fake()->numberBetween(10_000, 99_999),
            'reporting_agency' => 'Montpelier PD',
            'reporting_agency_id' => 'VT0040200',
            'animal' => 'None/Other',
            'time_of_day' => 'Day',
            'impairment' => 'None',
            'road_characteristics' => 'Not at a Junction',
            'road_condition' => 'None',
            'surface_condition' => 'Dry',
        ];
    }

    /**
     * A crash somebody was hurt in.
     */
    public function injury(): static
    {
        return $this->state(['crash_type' => Crash::TYPE_INJURY]);
    }

    /**
     * A crash somebody died in.
     */
    public function fatal(): static
    {
        return $this->state(['crash_type' => Crash::TYPE_FATAL]);
    }

    /**
     * Placed at a given point, for matching against a road.
     */
    public function at(float $latitude, float $longitude): static
    {
        return $this->state(['geom' => Point::makeGeodetic($latitude, $longitude)]);
    }

    /**
     * Exported without a usable location, so it can never reach a road.
     */
    public function unplaceable(): static
    {
        return $this->state(['geom' => null]);
    }
}
