<?php

namespace App\Models;

use App\Concerns\HasMappableGeometry;
use Clickbar\Magellan\Data\Geometries\LineString;
use Database\Factories\RouteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A cycling route uploaded by a user, stored as a WGS84 line.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $source
 * @property string|null $source_id
 * @property LineString $geom
 * @property int $distance_m
 * @property int $elevation_gain_m
 * @property int $point_count
 * @property string|null $original_gpx_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'name',
    'source',
    'source_id',
    'geom',
    'distance_m',
    'elevation_gain_m',
    'point_count',
    'original_gpx_path',
])]
class Route extends Model
{
    /** @use HasFactory<RouteFactory> */
    use HasFactory, HasMappableGeometry;

    /**
     * A route uploaded from a GPX file.
     */
    public const SOURCE_GPX = 'gpx';

    /**
     * A route imported from a connected Ride with GPS account.
     */
    public const SOURCE_RWGPS = 'rwgps';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geom' => LineString::class,
            'distance_m' => 'integer',
            'elevation_gain_m' => 'integer',
            'point_count' => 'integer',
        ];
    }

    /**
     * The user who owns this route.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every time this route has been checked against the road network.
     *
     * @return HasMany<RouteAnalysis, $this>
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(RouteAnalysis::class);
    }

    /**
     * The route distance in kilometres.
     */
    public function distanceKm(): float
    {
        return round($this->distance_m / 1000, 1);
    }
}
