<?php

namespace App\Models;

use App\Concerns\HasMappableGeometry;
use Clickbar\Magellan\Data\Geometries\LineString;
use Database\Factories\FlaggedSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A continuous stretch of a route, as one analysis of it found it.
 *
 * Every analysis writes the route out as a sequence of these: the stretches
 * `flagged` as too dangerous for the rider's threshold, and the ones in
 * between that were not. Both are kept so the route can be read in order,
 * rather than as warnings against a blank.
 *
 * `start_fraction` and `end_fraction` locate it along the parent route line,
 * which is what lets a detour be spliced in with ST_LineSubstring later.
 *
 * @property int $id
 * @property int $route_analysis_id
 * @property float $start_fraction
 * @property float $end_fraction
 * @property LineString $geom
 * @property int $length_m
 * @property int $max_danger
 * @property bool $flagged
 * @property list<string>|null $reasons
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'route_analysis_id',
    'start_fraction',
    'end_fraction',
    'geom',
    'length_m',
    'max_danger',
    'flagged',
    'reasons',
])]
class FlaggedSection extends Model
{
    /** @use HasFactory<FlaggedSectionFactory> */
    use HasFactory, HasMappableGeometry;

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
            'max_danger' => 'integer',
            'flagged' => 'boolean',
            'reasons' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RouteAnalysis, $this>
     */
    public function routeAnalysis(): BelongsTo
    {
        return $this->belongsTo(RouteAnalysis::class);
    }

    /**
     * The roads this section runs along.
     *
     * @return BelongsToMany<RoadSegment, $this>
     */
    public function roadSegments(): BelongsToMany
    {
        return $this->belongsToMany(RoadSegment::class, 'flagged_section_road_segment');
    }

    /**
     * The section length in kilometres.
     */
    public function lengthKm(): float
    {
        return round($this->length_m / 1000, 1);
    }
}
