<?php

namespace Database\Factories;

use App\Models\Route;
use App\Models\RouteAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteAnalysis>
 */
class RouteAnalysisFactory extends Factory
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
            'status' => RouteAnalysis::STATUS_PENDING,
            'risk_tolerance' => RouteAnalysis::TOLERANCE_NORMAL,
            'started_at' => null,
            'finished_at' => null,
            'failure_reason' => null,
            'summary' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RouteAnalysis::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RouteAnalysis::STATUS_COMPLETED,
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
            'summary' => ['sections' => 0, 'flagged_m' => 0, 'max_danger' => 0],
        ]);
    }

    public function failed(string $reason = 'Something went wrong.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RouteAnalysis::STATUS_FAILED,
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
            'failure_reason' => $reason,
        ]);
    }

    public function tolerance(string $tolerance): static
    {
        return $this->state(fn (array $attributes): array => [
            'risk_tolerance' => $tolerance,
        ]);
    }
}
