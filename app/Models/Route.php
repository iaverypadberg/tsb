<?php

namespace App\Models;

use App\Concerns\HasMappableGeometry;
use Clickbar\Magellan\Data\Geometries\LineString;
use Database\Factories\RouteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A cycling route uploaded by a user, stored as a WGS84 line.
 *
 * It belongs either to a rider with an account or, until they sign up, to the
 * browser session that uploaded it. Exactly one of `user_id` and `guest_token`
 * is set, which the database enforces.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property string|null $guest_token
 * @property Carbon|null $shared_at
 * @property Carbon|null $expires_at
 * @property string $name
 * @property string $source
 * @property string|null $source_id
 * @property LineString $geom
 * @property int $distance_m
 * @property int $elevation_gain_m
 * @property int $point_count
 * @property string|null $original_gpx_path
 * @property string $traffic_status
 * @property Carbon|null $traffic_queued_at
 * @property Carbon|null $traffic_profiled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'guest_token',
    'shared_at',
    'expires_at',
    'name',
    'source',
    'source_id',
    'geom',
    'distance_m',
    'elevation_gain_m',
    'point_count',
    'original_gpx_path',
    'traffic_status',
    'traffic_queued_at',
    'traffic_profiled_at',
])]
class Route extends Model
{
    /** @use HasFactory<RouteFactory> */
    use HasFactory, HasMappableGeometry, HasUuids;

    /**
     * A route uploaded from a GPX file.
     */
    public const SOURCE_GPX = 'gpx';

    /**
     * A route imported from a connected Ride with GPS account.
     */
    public const SOURCE_RWGPS = 'rwgps';

    /**
     * Nobody has looked at this route's traffic yet.
     *
     * The state a route uploaded before traffic profiling existed sits in,
     * and the one that makes it queue itself when its page is opened.
     */
    public const TRAFFIC_UNPROFILED = 'unprofiled';

    /**
     * The job is dispatched and has not finished.
     */
    public const TRAFFIC_QUEUED = 'queued';

    public const TRAFFIC_COMPLETED = 'completed';

    public const TRAFFIC_FAILED = 'failed';

    /**
     * How long a queued traffic profile is given before it is written off.
     *
     * Matching a route takes a second or two, so a quarter of an hour is not
     * a slow job: it is a job that is not running. Something has to put a
     * limit on the wait, because the two states that keep the page saying
     * "working" are both states a dropped job sits in for ever. The job
     * records its own failure when it fails, but nothing records a job that
     * never reached a worker at all — a queue with no worker on it, or a
     * worker that was killed mid-run and took the attempt with it.
     *
     * Writing it off is not giving up on it: the status is untouched, so a
     * profile that lands later than this still shows up. All that expires is
     * the page's willingness to keep asking.
     */
    public const TRAFFIC_STALL_AFTER_MINUTES = 15;

    /**
     * How long an upload nobody has claimed lives, in days.
     *
     * Long enough to read the route, sleep on it and send the link to the
     * person you are riding with; short enough that an endpoint anyone can
     * post to is not storage.
     */
    public const GUEST_LIFETIME_DAYS = 7;

    /**
     * Identify a route in a URL by its public id, not by the counter.
     *
     * A sequential id in a link tells whoever holds it how many routes the
     * site has and where in the queue theirs sits. Nothing good comes of
     * either, so links carry the random id and `id` stays internal.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Fill `public_id`, rather than the primary key, on create.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * A version 4 UUID: random throughout.
     *
     * Overrides the trait's version 7, whose leading bits are the moment it
     * was minted. Ordering is of no use here and the point of the column is
     * to say nothing at all.
     */
    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geom' => LineString::class,
            'distance_m' => 'integer',
            'elevation_gain_m' => 'integer',
            'point_count' => 'integer',
            'traffic_queued_at' => 'datetime',
            'traffic_profiled_at' => 'datetime',
            'shared_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The user who owns this route.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every time this route has been checked against the road network.
     *
     * @return HasMany<RouteAnalysis, $this>
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(RouteAnalysis::class);
    }

    /**
     * How much traffic the route carries, stretch by stretch.
     *
     * @return HasMany<TrafficSection, $this>
     */
    public function trafficSections(): HasMany
    {
        return $this->hasMany(TrafficSection::class);
    }

    /**
     * Whether this route is still waiting to be claimed by an account.
     */
    public function isGuestUpload(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Whether anyone holding the link may read this route.
     */
    public function isShared(): bool
    {
        return $this->shared_at !== null;
    }

    /**
     * Whether this route's time is up.
     *
     * Asked of the clock rather than of whether the pruner has been round
     * yet: the page stops showing an expired route the moment it said it
     * would, and deleting it is tidying up after that, not the event itself.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether the traffic profile is still being worked out.
     *
     * The wait is given a deadline, because the status alone cannot tell a
     * job that is running from one that was never picked up: both sit on
     * queued. Past the deadline the page stops asking and says so, rather
     * than polling for the lifetime of the tab.
     */
    public function isProfilingTraffic(): bool
    {
        return $this->isAwaitingTraffic() && ! $this->hasStalledTraffic();
    }

    /**
     * Whether the traffic profile was asked for and has not arrived.
     *
     * Both states a route waits in: never asked about, and asked about but
     * not answered. The clock below is what separates the two above.
     */
    public function isAwaitingTraffic(): bool
    {
        return in_array($this->traffic_status, [self::TRAFFIC_UNPROFILED, self::TRAFFIC_QUEUED], true);
    }

    /**
     * Whether the wait has gone on long enough to call it off.
     *
     * Measured from when the job was queued, falling back to when the row
     * was last written for the routes that predate that column.
     */
    public function hasStalledTraffic(): bool
    {
        if (! $this->isAwaitingTraffic()) {
            return false;
        }

        $since = $this->traffic_queued_at ?? $this->updated_at;

        return $since === null
            || $since->addMinutes(self::TRAFFIC_STALL_AFTER_MINUTES)->isPast();
    }

    /**
     * Whether the page has nothing more to wait for and no traffic to show.
     *
     * The failure the job recorded and the failure nobody recorded read the
     * same way to a rider: the answer is not coming.
     */
    public function hasUnresolvedTraffic(): bool
    {
        return $this->traffic_status === self::TRAFFIC_FAILED
            || $this->hasStalledTraffic();
    }

    /**
     * The route distance in kilometres.
     */
    public function distanceKm(): float
    {
        return round($this->distance_m / 1000, 1);
    }
}
