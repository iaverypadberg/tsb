<?php

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\LineString;
use Database\Factories\RoadSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A stretch of road, normally one OpenStreetMap way, with a danger score.
 *
 * @property int $id
 * @property int|null $osm_way_id
 * @property string|null $name
 * @property LineString $geom
 * @property string|null $highway_type
 * @property int|null $speed_limit_kmh
 * @property int|null $lanes
 * @property bool|null $has_shoulder
 * @property string $bike_infra
 * @property int $danger_score
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'osm_way_id',
    'name',
    'geom',
    'highway_type',
    'speed_limit_kmh',
    'lanes',
    'has_shoulder',
    'bike_infra',
    'danger_score',
    'source',
])]
class RoadSegment extends Model
{
    /** @use HasFactory<RoadSegmentFactory> */
    use HasFactory;

    /**
     * No cycling provision at all.
     */
    public const INFRA_NONE = 'none';

    /**
     * A painted bike lane, with nothing separating it from traffic.
     */
    public const INFRA_PAINTED = 'painted';

    /**
     * A painted lane with a buffer zone.
     */
    public const INFRA_BUFFERED = 'buffered';

    /**
     * Physically separated from traffic.
     */
    public const INFRA_PROTECTED = 'protected';

    /**
     * A standalone path or trail, away from the road.
     */
    public const INFRA_PATH = 'path';

    /**
     * Every bike infrastructure value, safest last.
     *
     * @var list<string>
     */
    public const INFRA_TYPES = [
        self::INFRA_NONE,
        self::INFRA_PAINTED,
        self::INFRA_BUFFERED,
        self::INFRA_PROTECTED,
        self::INFRA_PATH,
    ];

    /**
     * The highest a danger score can go.
     */
    public const MAX_DANGER = 100;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geom' => LineString::class,
            'osm_way_id' => 'integer',
            'speed_limit_kmh' => 'integer',
            'lanes' => 'integer',
            'has_shoulder' => 'boolean',
            'danger_score' => 'integer',
        ];
    }

    /**
     * Reports of why this road is bad to ride.
     *
     * @return HasMany<SegmentReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(SegmentReport::class);
    }
}
