<?php

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\MultiLineString;
use Database\Factories\AadtSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One stretch of Vermont highway with a measured traffic volume.
 *
 * Annual Average Daily Traffic is the number of vehicles a day, averaged
 * over a year, published by the Vermont Agency of Transportation. It is the
 * signal OpenStreetMap tags can only approximate: `road_segments` leans on
 * road class and a route number to guess how busy a road is, and this says
 * so outright.
 *
 * `Directional` is 0 on every published section, so the figure counts both
 * directions and must not be doubled.
 *
 * Coverage is partial by design: counts exist for Interstate, US and VT
 * highways, federal urban routes and the larger collectors. A road with no
 * section here is unmeasured, which is not the same as quiet.
 *
 * @property int $id
 * @property int $year
 * @property int $layer_id
 * @property int $aadt
 * @property MultiLineString $geom
 * @property string $ete_id
 * @property float $ete_begin_mm
 * @property float $ete_end_mm
 * @property float|null $begin_mm
 * @property float|null $end_mm
 * @property string|null $route_name
 * @property string|null $route_num
 * @property string|null $route_type
 * @property string|null $functional_class
 * @property string|null $standard_route_code
 * @property string|null $town_name
 * @property string|null $atr_station
 * @property string|null $atr_status
 * @property string|null $location_error
 * @property bool|null $is_divided
 * @property Carbon|null $extracted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'year',
    'layer_id',
    'aadt',
    'geom',
    'ete_id',
    'ete_begin_mm',
    'ete_end_mm',
    'begin_mm',
    'end_mm',
    'route_name',
    'route_num',
    'route_type',
    'functional_class',
    'standard_route_code',
    'town_name',
    'atr_station',
    'atr_status',
    'location_error',
    'is_divided',
    'extracted_at',
])]
class AadtSection extends Model
{
    /** @use HasFactory<AadtSectionFactory> */
    use HasFactory;

    /**
     * Limited access roads: the interstates, their ramps, and the divided
     * sections of US and VT highways.
     */
    public const LAYER_LIMITED = 0;

    /**
     * Everything else, which is the part a rider might actually be on.
     */
    public const LAYER_OTHER = 1;

    /**
     * Freeway ramps, which carry a count but are not ridable.
     */
    public const ROUTE_TYPE_RAMP = 'RAMP';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'layer_id' => 'integer',
            'aadt' => 'integer',
            'geom' => MultiLineString::class,
            'ete_begin_mm' => 'float',
            'ete_end_mm' => 'float',
            'begin_mm' => 'float',
            'end_mm' => 'float',
            'is_divided' => 'boolean',
            'extracted_at' => 'datetime',
        ];
    }

    /**
     * The roads this count was matched to.
     *
     * @return BelongsToMany<RoadSegment, $this>
     */
    public function roadSegments(): BelongsToMany
    {
        return $this->belongsToMany(RoadSegment::class)->withPivot('samples');
    }

    /**
     * The most recent year any counts were imported for.
     */
    public static function latestYear(): ?int
    {
        $year = static::query()->max('year');

        return $year === null ? null : (int) $year;
    }
}
