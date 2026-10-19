<?php

namespace App\Models;

use App\Concerns\HasMappableGeometry;
use Clickbar\Magellan\Data\Geometries\LineString;
use Database\Factories\RoadSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A stretch of road, normally one OpenStreetMap way, with a danger score.
 *
 * @property int $id
 * @property int|null $osm_way_id
 * @property string|null $name
 * @property string|null $ref
 * @property LineString $geom
 * @property string|null $highway_type
 * @property string|null $surface
 * @property int|null $speed_limit_kmh
 * @property int|null $lanes
 * @property bool|null $has_shoulder
 * @property string $bike_infra
 * @property int $danger_score
 * @property int $base_danger_score
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'osm_way_id',
    'name',
    'ref',
    'geom',
    'highway_type',
    'surface',
    'speed_limit_kmh',
    'lanes',
    'has_shoulder',
    'bike_infra',
    'danger_score',
    'base_danger_score',
    'source',
])]
class RoadSegment extends Model
{
    /** @use HasFactory<RoadSegmentFactory> */
    use HasFactory, HasMappableGeometry;

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
            'base_danger_score' => 'integer',
            'aadt' => 'integer',
            'aadt_year' => 'integer',
            'aadt_sample_share' => 'float',
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

    /**
     * The published traffic counts this road was matched to.
     *
     * More than one is normal: a way often spans two counted sections.
     *
     * @return BelongsToMany<AadtSection, $this>
     */
    public function aadtSections(): BelongsToMany
    {
        return $this->belongsToMany(AadtSection::class)->withPivot('samples');
    }

    /**
     * Rider flags covering this road, at any stage of review.
     *
     * @return HasMany<RoadFlagSegment, $this>
     */
    public function flagSegments(): HasMany
    {
        return $this->hasMany(RoadFlagSegment::class);
    }

    /**
     * What a rider would call this road: its route number if it has one,
     * otherwise its name, otherwise what kind of road it is.
     */
    public function roadKey(): string
    {
        return $this->ref ?? $this->name ?? $this->highway_type ?? 'road';
    }
}
