<?php

namespace Database\Seeders;

use App\Models\RoadSegment;
use App\Models\SegmentReport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Attaches a handful of example reports to real imported roads.
 *
 * These are PLACEHOLDERS. They exist so the reporting mechanism and its
 * effect on danger scores can be exercised end to end; they are not verified
 * incident data. The outline treats collecting genuine "known bad road"
 * information as a separate task, and nothing here should be mistaken for it.
 *
 * Each entry records road character that is a matter of observation rather
 * than statistics. No crash counts are invented.
 *
 * Reports only reach `danger_score` once `php artisan osm:build-segments`
 * runs again, which recomputes every score from the tags plus the reports.
 */
class KnownBadRoadSeeder extends Seeder
{
    /**
     * Route references, matched against imported segments.
     *
     * @var list<array{ref: string, reason: string, severity: int, limit: int}>
     */
    private const EXAMPLES = [
        [
            'ref' => 'US 7',
            'reason' => 'Heavy traffic with little or no shoulder',
            'severity' => 4,
            'limit' => 25,
        ],
        [
            'ref' => 'US 2',
            'reason' => 'Fast moving traffic, narrow in places',
            'severity' => 3,
            'limit' => 25,
        ],
        [
            'ref' => 'US 4',
            'reason' => 'Steep grades combined with truck traffic',
            'severity' => 3,
            'limit' => 15,
        ],
    ];

    public function run(): void
    {
        foreach (self::EXAMPLES as $example) {
            $segments = RoadSegment::query()
                ->where('ref', $example['ref'])
                ->where('source', 'osm')
                ->orderBy('id')
                ->limit($example['limit'])
                ->get();

            if ($segments->isEmpty()) {
                Log::warning('No road segments matched a seeded reference.', [
                    'ref' => $example['ref'],
                    'hint' => 'Run `php artisan osm:import` then `php artisan osm:build-segments`.',
                ]);

                continue;
            }

            foreach ($segments as $segment) {
                SegmentReport::query()->updateOrCreate(
                    [
                        'road_segment_id' => $segment->id,
                        'reason' => $example['reason'],
                    ],
                    [
                        'severity' => $example['severity'],
                        'source' => SegmentReport::SOURCE_MANUAL,
                        'notes' => 'Example seed data, not verified incident data.',
                    ],
                );
            }

            Log::info('Seeded example reports.', [
                'ref' => $example['ref'],
                'segments' => $segments->count(),
            ]);
        }
    }
}
