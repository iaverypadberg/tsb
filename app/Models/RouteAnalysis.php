<?php

namespace App\Models;

use Database\Factories\RouteAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One run of the detection pipeline over a route.
 *
 * @property int $id
 * @property int $route_id
 * @property string $status
 * @property string $risk_tolerance
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $summary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'route_id',
    'status',
    'risk_tolerance',
    'started_at',
    'finished_at',
    'failure_reason',
    'summary',
])]
class RouteAnalysis extends Model
{
    /** @use HasFactory<RouteAnalysisFactory> */
    use HasFactory;

    /**
     * Queued, not yet picked up.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Being worked on now.
     */
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * Flag anything with a hint of main road about it.
     */
    public const TOLERANCE_CAUTIOUS = 'cautious';

    /**
     * The default: flag busy roads, leave quiet ones alone.
     */
    public const TOLERANCE_NORMAL = 'normal';

    /**
     * Only flag roads that are genuinely hostile.
     */
    public const TOLERANCE_BRAVE = 'brave';

    /**
     * The danger score at which a road is flagged, per tolerance.
     *
     * @var array<string, int>
     */
    public const THRESHOLDS = [
        self::TOLERANCE_CAUTIOUS => 40,
        self::TOLERANCE_NORMAL => 60,
        self::TOLERANCE_BRAVE => 80,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    /**
     * The danger score at or above which this analysis flags a road.
     */
    public function threshold(): int
    {
        return self::THRESHOLDS[$this->risk_tolerance] ?? self::THRESHOLDS[self::TOLERANCE_NORMAL];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * @return HasMany<FlaggedSection, $this>
     */
    public function flaggedSections(): HasMany
    {
        return $this->hasMany(FlaggedSection::class);
    }
}
