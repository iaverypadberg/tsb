<?php

namespace Database\Factories;

use App\Models\RoadSegment;
use App\Models\SegmentReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SegmentReport>
 */
class SegmentReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'road_segment_id' => RoadSegment::factory(),
            'user_id' => null,
            'reason' => fake()->randomElement([
                'No shoulder',
                'Blind bend',
                'Heavy truck traffic',
                'Narrow bridge',
                'Poor surface',
            ]),
            'severity' => fake()->numberBetween(SegmentReport::MIN_SEVERITY, SegmentReport::MAX_SEVERITY),
            'source' => SegmentReport::SOURCE_MANUAL,
            'notes' => null,
        ];
    }

    /**
     * A report at a given severity.
     */
    public function severity(int $severity): static
    {
        return $this->state(fn (array $attributes): array => [
            'severity' => $severity,
        ]);
    }
}
