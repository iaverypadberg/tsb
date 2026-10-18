<?php

namespace App\Actions\RoadFlags;

use App\Actions\RoadSegments\RecalculateDangerScores;
use App\Models\RoadFlag;
use App\Models\SegmentReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What an admin does with a rider's flag.
 *
 * Accepting it writes one report per road it covers, which is what the
 * scoring already understands, and rescores those roads straight away.
 * Rejecting it takes any such reports back out again, so a decision can be
 * changed without leaving a road scored on something nobody believes.
 */
class ReviewRoadFlag
{
    public function __construct(private RecalculateDangerScores $recalculate) {}

    public function accept(RoadFlag $flag, User $admin, ?string $note = null): RoadFlag
    {
        DB::transaction(function () use ($flag, $admin, $note): void {
            $flag->reports()->delete();

            foreach ($this->roadSegmentIds($flag) as $roadSegmentId) {
                SegmentReport::create([
                    'road_segment_id' => $roadSegmentId,
                    'user_id' => $flag->user_id,
                    'road_flag_id' => $flag->id,
                    'reason' => $flag->reasonList(),
                    'severity' => $flag->severity,
                    'source' => SegmentReport::SOURCE_RIDER,
                    'notes' => $flag->notes,
                ]);
            }

            $this->finish($flag, $admin, RoadFlag::STATUS_ACCEPTED, $note);
        });

        return $flag->refresh();
    }

    public function reject(RoadFlag $flag, User $admin, ?string $note = null): RoadFlag
    {
        DB::transaction(function () use ($flag, $admin, $note): void {
            $flag->reports()->delete();

            $this->finish($flag, $admin, RoadFlag::STATUS_REJECTED, $note);
        });

        return $flag->refresh();
    }

    /**
     * Record who decided what, then put the affected roads back in step
     * with the reports now held against them.
     */
    private function finish(RoadFlag $flag, User $admin, string $status, ?string $note): void
    {
        $flag->update([
            'status' => $status,
            'reviewed_by_id' => $admin->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->recalculate->handle($this->roadSegmentIds($flag));
    }

    /**
     * The roads the flag covers.
     *
     * @return list<int>
     */
    private function roadSegmentIds(RoadFlag $flag): array
    {
        return array_values(array_map(
            intval(...),
            $flag->roadSegments()->pluck('road_segments.id')->all(),
        ));
    }
}
