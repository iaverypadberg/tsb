<?php

namespace Database\Factories;

use App\Models\RoadFlag;
use App\Models\SegmentReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadFlag>
 */
class RoadFlagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reasons' => fake()->randomElements(array_slice(RoadFlag::REASONS, 0, -1), 2),
            'severity' => fake()->numberBetween(SegmentReport::MIN_SEVERITY, SegmentReport::MAX_SEVERITY),
            'notes' => fake()->sentence(12),
            'status' => RoadFlag::STATUS_PENDING,
            'length_m' => fake()->numberBetween(200, 8_000),
        ];
    }

    /**
     * A flag an admin has already acted on.
     */
    public function accepted(): static
    {
        return $this->reviewed(RoadFlag::STATUS_ACCEPTED);
    }

    public function rejected(): static
    {
        return $this->reviewed(RoadFlag::STATUS_REJECTED);
    }

    public function severity(int $severity): static
    {
        return $this->state(fn (array $attributes): array => [
            'severity' => $severity,
        ]);
    }

    private function reviewed(string $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'reviewed_by_id' => User::factory()->admin(),
            'reviewed_at' => now(),
        ]);
    }
}
