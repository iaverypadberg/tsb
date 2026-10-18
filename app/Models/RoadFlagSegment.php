<?php

namespace App\Models;

use App\Concerns\HasMappableGeometry;
use Clickbar\Magellan\Data\Geometries\LineString;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch of road covered by a flag.
 *
 * `start_fraction` and `end_fraction` locate it along the road segment's
 * line: 0 to 1 when the rider flagged the whole way, a narrower pair when
 * they picked out a section of it.
 *
 * @property int $id
 * @property int $road_flag_id
 * @property int $road_segment_id
 * @property float $start_fraction
 * @property float $end_fraction
 * @property LineString $geom
 * @property int $length_m
 */
#[Fillable([
    'road_flag_id',
    'road_segment_id',
    'start_fraction',
    'end_fraction',
    'geom',
    'length_m',
])]
class RoadFlagSegment extends Model
{
    use HasMappableGeometry;

    /**
     * The rows are written in one statement with the geometry, and carry no
     * history of their own.
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geom' => LineString::class,
            'start_fraction' => 'float',
            'end_fraction' => 'float',
            'length_m' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RoadFlag, $this>
     */
    public function roadFlag(): BelongsTo
    {
        return $this->belongsTo(RoadFlag::class);
    }

    /**
     * @return BelongsTo<RoadSegment, $this>
     */
    public function roadSegment(): BelongsTo
    {
        return $this->belongsTo(RoadSegment::class);
    }

    /**
     * Whether the rider picked out part of the road rather than all of it.
     */
    public function isPartial(): bool
    {
        return $this->start_fraction > 0.0 || $this->end_fraction < 1.0;
    }
}
