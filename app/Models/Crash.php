<?php

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Point;
use Database\Factories\CrashFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One crash reported to the Vermont Agency of Transportation.
 *
 * This is the signal the road's tags and its traffic count cannot give:
 * where people have actually been hurt. A road can look unremarkable in
 * OpenStreetMap and still be the one with a crash at every junction.
 *
 * The export is a police report rendered as a row, so most of it is the
 * report form's vocabulary and is held as text exactly as published. Three
 * fields do the work: the point, the date, and the severity.
 *
 * @property int $id
 * @property int $object_id
 * @property Carbon|null $occurred_at
 * @property Point|null $geom
 * @property int|null $road_segment_id
 * @property float|null $match_distance_m
 * @property string|null $crash_type
 * @property string|null $involving
 * @property string|null $town
 * @property string|null $address
 * @property string|null $street_address
 * @property string|null $non_reportable_address
 * @property string|null $aot_route
 * @property string|null $aot_route_id
 * @property float|null $aot_milepoint
 * @property string|null $collision_direction
 * @property string|null $weather
 * @property string|null $road_group
 * @property int|null $road_group_id
 * @property string|null $report_number
 * @property string|null $reporting_agency
 * @property string|null $reporting_agency_id
 * @property string|null $animal
 * @property string|null $time_of_day
 * @property string|null $intersection_with
 * @property string|null $impairment
 * @property string|null $road_characteristics
 * @property string|null $road_condition
 * @property string|null $surface_condition
 * @property string|null $mapped
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'object_id',
    'occurred_at',
    'geom',
    'road_segment_id',
    'match_distance_m',
    'crash_type',
    'involving',
    'town',
    'address',
    'street_address',
    'non_reportable_address',
    'aot_route',
    'aot_route_id',
    'aot_milepoint',
    'collision_direction',
    'weather',
    'road_group',
    'road_group_id',
    'report_number',
    'reporting_agency',
    'reporting_agency_id',
    'animal',
    'time_of_day',
    'intersection_with',
    'impairment',
    'road_characteristics',
    'road_condition',
    'surface_condition',
    'mapped',
])]
class Crash extends Model
{
    /** @use HasFactory<CrashFactory> */
    use HasFactory;

    /**
     * Someone died.
     */
    public const TYPE_FATAL = 'Fatal';

    /**
     * Someone was hurt but survived.
     */
    public const TYPE_INJURY = 'Injury';

    /**
     * Nobody was hurt.
     */
    public const TYPE_PROPERTY_DAMAGE = 'Property Damage Only';

    /**
     * Crash types where a person was hurt or killed.
     *
     * @var list<string>
     */
    public const HARM_TYPES = [self::TYPE_FATAL, self::TYPE_INJURY];

    /**
     * A rider was in it.
     */
    public const INVOLVING_BICYCLE = 'Bicycle';

    /**
     * Somebody on foot was in it.
     */
    public const INVOLVING_PEDESTRIAN = 'Pedestrian';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'object_id' => 'integer',
            'occurred_at' => 'datetime',
            'geom' => Point::class,
            'road_segment_id' => 'integer',
            'match_distance_m' => 'float',
            'aot_milepoint' => 'float',
            'road_group_id' => 'integer',
        ];
    }

    /**
     * The road this crash was matched to, once `crashes:match` has run.
     *
     * @return BelongsTo<RoadSegment, $this>
     */
    public function roadSegment(): BelongsTo
    {
        return $this->belongsTo(RoadSegment::class);
    }
}
