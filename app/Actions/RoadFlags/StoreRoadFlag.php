<?php

namespace App\Actions\RoadFlags;

use App\Models\RoadFlag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records what a rider picked out on the map, and why.
 *
 * The geometry of each flagged stretch is cut in the database rather than
 * sent up from the browser: the client says which way and how far along it,
 * and Postgres decides what that actually looks like on the ground.
 */
class StoreRoadFlag
{
    /**
     * @param  list<string>  $reasons  Everything the rider says is wrong
     *                                 with the road.
     */
    public function handle(
        User $user,
        RoadSelection $selection,
        array $reasons,
        int $severity,
        string $notes,
    ): RoadFlag {
        return DB::transaction(function () use ($user, $selection, $reasons, $severity, $notes): RoadFlag {
            $flag = RoadFlag::create([
                'user_id' => $user->id,
                'reasons' => $reasons,
                'severity' => $severity,
                'notes' => $notes,
                'status' => RoadFlag::STATUS_PENDING,
            ]);

            $this->storeSegments($flag, $selection);

            $flag->update([
                'length_m' => (int) DB::scalar(
                    'select coalesce(sum(length_m), 0) from road_flag_segments where road_flag_id = ?',
                    [$flag->id],
                ),
            ]);

            return $flag->refresh();
        });
    }

    /**
     * Written as one statement: a whole-road selection can cover a couple
     * of hundred ways, and each of them needs its geometry cut to size.
     */
    private function storeSegments(RoadFlag $flag, RoadSelection $selection): void
    {
        if ($selection->isEmpty()) {
            return;
        }

        $ids = [];
        $starts = [];
        $ends = [];

        foreach ($selection->parts as $part) {
            $ids[] = $part->roadSegmentId;
            $starts[] = max(0.0, min(1.0, $part->startFraction));
            $ends[] = max(0.0, min(1.0, $part->endFraction));
        }

        DB::insert(<<<'SQL'
            insert into road_flag_segments (
                road_flag_id, road_segment_id, start_fraction, end_fraction, geom, length_m
            )
            select
                :flag_id,
                rs.id,
                part.start_fraction,
                part.end_fraction,
                ST_LineSubstring(rs.geom, part.start_fraction, part.end_fraction),
                ST_Length(ST_LineSubstring(rs.geom, part.start_fraction, part.end_fraction)::geography)::int
            from unnest(
                :ids::bigint[],
                :starts::double precision[],
                :ends::double precision[]
            ) as part(id, start_fraction, end_fraction)
            join road_segments rs on rs.id = part.id
        SQL, [
            'flag_id' => $flag->id,
            'ids' => $this->postgresArray($ids),
            'starts' => $this->postgresArray($starts),
            'ends' => $this->postgresArray($ends),
        ]);
    }

    /**
     * PDO has no array type, so numbers go across as an array literal.
     *
     * @param  list<int|float>  $values
     */
    private function postgresArray(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }
}
