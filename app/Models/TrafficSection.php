<?php

namespace App\Models;

use App\Actions\Aadt\TrafficBand;
use App\Actions\RoadSegments\InterpretOsmHighway;
use App\Concerns\HasMappableGeometry;
use Clickbar\Magellan\Data\Geometries\LineString;
use Database\Factories\TrafficSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A stretch of a route carrying one band of traffic.
 *
 * A route is cut into these once, when it is uploaded, and they belong to
 * the route rather than to any analysis of it: how busy a road is does not
 * depend on how brave the rider is. That is what lets the map colour a route
 * by traffic the moment it lands, before anybody has asked for an analysis.
 *
 * @property int $id
 * @property int $route_id
 * @property float $start_fraction
 * @property float $end_fraction
 * @property LineString $geom
 * @property int $length_m
 * @property TrafficBand $band
 * @property string|null $road_name
 * @property int|null $aadt
 * @property int|null $speed_limit_kmh
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'route_id',
    'start_fraction',
    'end_fraction',
    'geom',
    'length_m',
    'band',
    'road_name',
    'aadt',
    'speed_limit_kmh',
])]
class TrafficSection extends Model
{
    /** @use HasFactory<TrafficSectionFactory> */
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
            'band' => TrafficBand::class,
            'aadt' => 'integer',
            'speed_limit_kmh' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * The road's name as a rider would read it aloud.
     *
     * OpenStreetMap writes a road carrying two route numbers as "US 2;US 7",
     * which is a tag rather than a name, so the separator is spelled out.
     */
    public function roadLabel(): ?string
    {
        return $this->road_name === null
            ? null
            : str_replace(';', ' / ', $this->road_name);
    }

    /**
     * The stretch length in kilometres.
     */
    public function lengthKm(): float
    {
        return round($this->length_m / 1000, 1);
    }

    /**
     * How fast the traffic on this stretch is allowed to go, written out.
     *
     * Shown in mph, which is the unit on the sign the rider is riding past.
     * The column is km/h to match `road_segments.speed_limit_kmh`, and the
     * conversion lands back on the figure OpenStreetMap was tagged with.
     *
     * Null where nothing along the stretch is tagged with a limit, which is
     * most of them: an untagged road is unknown rather than slow, so the
     * card says nothing instead of guessing at the default.
     */
    public function speedLabel(): ?string
    {
        $mph = (new InterpretOsmHighway)->speedLimitMph($this->speed_limit_kmh);

        if ($mph === null) {
            return null;
        }

        $label = __(':speed mph', ['speed' => $mph]);

        // `__()` is typed as able to return a whole translation group as
        // well as one line, which this key never is. The bare figure still
        // reads if it ever did.
        return is_string($label) ? $label : $mph.' mph';
    }
}
