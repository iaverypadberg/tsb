<?php

namespace App\Models;

use Database\Factories\SegmentReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recorded reason that a stretch of road is bad to ride.
 *
 * This is where local knowledge lives, as opposed to what can be read off
 * OpenStreetMap tags: a blind bend, a bridge with no shoulder, a junction
 * riders know to avoid.
 *
 * @property int $id
 * @property int $road_segment_id
 * @property int|null $user_id
 * @property string $reason
 * @property int $severity
 * @property string $source
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'road_segment_id',
    'user_id',
    'reason',
    'severity',
    'source',
    'notes',
])]
class SegmentReport extends Model
{
    /** @use HasFactory<SegmentReportFactory> */
    use HasFactory;

    /**
     * Entered by hand, by a maintainer rather than a rider.
     */
    public const SOURCE_MANUAL = 'manual';

    /**
     * Reported through the app by someone who rode it.
     */
    public const SOURCE_RIDER = 'rider';

    /**
     * Derived from a published crash or incident dataset.
     */
    public const SOURCE_CRASH_DATA = 'crash_data';

    /**
     * The weakest and strongest a report can be.
     */
    public const MIN_SEVERITY = 1;

    public const MAX_SEVERITY = 5;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => 'integer',
        ];
    }

    /**
     * The road this report is about.
     *
     * @return BelongsTo<RoadSegment, $this>
     */
    public function roadSegment(): BelongsTo
    {
        return $this->belongsTo(RoadSegment::class);
    }

    /**
     * Who reported it, when it came from a rider.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
