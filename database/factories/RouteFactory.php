<?php

namespace Database\Factories;

use App\Models\Route;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\LineString;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Route>
 */
class RouteFactory extends Factory
{
    /**
     * Mean earth radius in metres, as used by the haversine formula.
     */
    private const EARTH_RADIUS_M = 6_371_008.8;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A short diagonal of evenly spaced points, starting near Montpelier VT.
        $coordinates = [];

        for ($i = 0; $i < 12; $i++) {
            $coordinates[] = [44.26 + ($i * 0.002), -72.58 + ($i * 0.003)];
        }

        return [
            'user_id' => User::factory(),
            'name' => fake()->streetName().' loop',
            'source' => Route::SOURCE_GPX,
            'source_id' => null,
            'original_gpx_path' => null,
            'elevation_gain_m' => fake()->numberBetween(0, 2_000),
            ...$this->attributesForCoordinates($coordinates),
        ];
    }

    /**
     * Build a route from an explicit list of [latitude, longitude] pairs.
     *
     * @param  list<array{float, float}>  $coordinates
     */
    public function withCoordinates(array $coordinates): static
    {
        return $this->state(fn (array $attributes): array => $this->attributesForCoordinates($coordinates));
    }

    /**
     * Derive the geometry and its measurements from one list of coordinates,
     * so a factory-built route is never self-contradictory.
     *
     * @param  list<array{float, float}>  $coordinates
     * @return array<string, mixed>
     */
    private function attributesForCoordinates(array $coordinates): array
    {
        $points = array_map(
            fn (array $pair): Point => Point::make($pair[1], $pair[0], srid: 4326),
            $coordinates,
        );

        return [
            'geom' => LineString::make($points, 4326),
            'point_count' => count($points),
            'distance_m' => $this->lengthInMetres($coordinates),
        ];
    }

    /**
     * Great-circle length of the line, close enough to what PostGIS reports
     * for the geography type.
     *
     * @param  list<array{float, float}>  $coordinates
     */
    private function lengthInMetres(array $coordinates): int
    {
        $metres = 0.0;

        for ($i = 1; $i < count($coordinates); $i++) {
            [$previousLatitude, $previousLongitude] = $coordinates[$i - 1];
            [$latitude, $longitude] = $coordinates[$i];

            $deltaLatitude = deg2rad($latitude - $previousLatitude);
            $deltaLongitude = deg2rad($longitude - $previousLongitude);

            $a = sin($deltaLatitude / 2) ** 2
                + cos(deg2rad($previousLatitude)) * cos(deg2rad($latitude)) * sin($deltaLongitude / 2) ** 2;

            $metres += 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
        }

        return (int) round($metres);
    }
}
