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
 * @property string $traffic_status
 * @property Carbon|null $traffic_profiled_at
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
    'traffic_status',
    'traffic_profiled_at',
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
     * Nobody has looked at this route's traffic yet.
     *
     * The state a route uploaded before traffic profiling existed sits in,
     * and the one that makes it queue itself when its page is opened.
     */
    public const TRAFFIC_UNPROFILED = 'unprofiled';

    /**
     * The job is dispatched and has not finished.
     */
    public const TRAFFIC_QUEUED = 'queued';

    public const TRAFFIC_COMPLETED = 'completed';

    public const TRAFFIC_FAILED = 'failed';

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
            'traffic_profiled_at' => 'datetime',
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
     * How much traffic the route carries, stretch by stretch.
     *
     * @return HasMany<TrafficSection, $this>
     */
    public function trafficSections(): HasMany
    {
        return $this->hasMany(TrafficSection::class);
    }

    /**
     * Whether the traffic profile is still being worked out.
     */
    public function isProfilingTraffic(): bool
    {
        return in_array($this->traffic_status, [self::TRAFFIC_UNPROFILED, self::TRAFFIC_QUEUED], true);
    }

    /**
     * The route distance in kilometres.
     */
    public function distanceKm(): float
    {
        return round($this->distance_m / 1000, 1);
    }
}
