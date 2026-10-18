<?php

namespace App\Models;

use Database\Factories\RoadFlagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A rider saying a road is dangerous, waiting for someone to read it.
 *
 * The extent lives in `road_flag_segments`: a flag covers either a whole
 * named road, which is many OpenStreetMap ways, or part of a single way.
 * Nothing here changes a danger score until it has been accepted.
 *
 * @property int $id
 * @property int|null $user_id
 * @property list<string> $reasons
 * @property int $severity
 * @property string $notes
 * @property string $status
 * @property int|null $reviewed_by_id
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property int $length_m
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'reasons',
    'severity',
    'notes',
    'status',
    'reviewed_by_id',
    'reviewed_at',
    'review_note',
    'length_m',
])]
class RoadFlag extends Model
{
    /** @use HasFactory<RoadFlagFactory> */
    use HasFactory;

    /**
     * Submitted, nobody has read it yet.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Read and believed: it now counts towards the road's score.
     */
    public const STATUS_ACCEPTED = 'accepted';

    /**
     * Read and set aside.
     */
    public const STATUS_REJECTED = 'rejected';

    /**
     * The things riders most often need to say about a road.
     *
     * A rider picks as many as apply, since a road is rarely bad for one
     * reason alone. Free text is still collected alongside; this only
     * saves them typing the common cases and keeps the queue skimmable.
     *
     * @var list<string>
     */
    public const REASONS = [
        'No shoulder',
        'Blind bend',
        'Heavy truck traffic',
        'Narrow bridge',
        'Poor surface',
        'Fast traffic',
        'Dangerous junction',
        'Other',
    ];

    /**
     * How bad a rider says it is, in words.
     *
     * Severity is the one number a rider sets, and "4 out of 5" means
     * nothing on its own, so both ends of the app name the steps.
     *
     * @var array<int, string>
     */
    public const SEVERITY_LABELS = [
        1 => 'Worth knowing about',
        2 => 'Unpleasant',
        3 => 'I avoid it',
        4 => 'Dangerous',
        5 => 'I would not send anyone there',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'severity' => 'integer',
            'reviewed_at' => 'datetime',
            'length_m' => 'integer',
        ];
    }

    /**
     * The rider who reported it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whoever read it.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /**
     * The stretches of road this flag covers.
     *
     * @return HasMany<RoadFlagSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(RoadFlagSegment::class);
    }

    /**
     * The roads underneath those stretches.
     *
     * @return BelongsToMany<RoadSegment, $this>
     */
    public function roadSegments(): BelongsToMany
    {
        return $this->belongsToMany(RoadSegment::class, 'road_flag_segments');
    }

    /**
     * The reports accepting this flag wrote, if it has been accepted.
     *
     * @return HasMany<SegmentReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(SegmentReport::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /**
     * Everything the rider said is wrong with the road, in one line.
     */
    public function reasonList(): string
    {
        return implode(' · ', $this->reasons ?? []);
    }

    /**
     * How bad the rider said it is, in words.
     */
    public function severityLabel(): string
    {
        return self::SEVERITY_LABELS[$this->severity] ?? (string) $this->severity;
    }

    /**
     * How much road the flag covers, in kilometres.
     */
    public function lengthKm(): float
    {
        return round($this->length_m / 1000, 1);
    }
}
