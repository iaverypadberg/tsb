<?php

namespace App\Console\Commands\Crashes;

use App\Actions\Crashes\MatchCrashesToRoadSegments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class MatchCrashes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crashes:match';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Work out which road each crash happened on and total them per road';

    public function handle(MatchCrashesToRoadSegments $matcher): int
    {
        $this->components->info('Matching crashes against the road network');

        $result = $matcher->handle();

        if ($result['crashes'] === 0) {
            $this->components->error('No crashes have been imported. Run `php artisan crashes:import` first.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Crashes held', $this->formatCount($result['crashes']));
        $this->components->twoColumnDetail('Matched to a road', $this->formatCount($result['matched']));
        $this->components->twoColumnDetail('Roads with a new total', $this->formatCount($result['roads']));
        $this->components->twoColumnDetail('Roads rescored', $this->formatCount($result['rescored']));

        $this->reportUnmatched($result['unplaceable'], $result['unmatched'], $result['crashes']);
        $this->reportWorstRoads();

        return self::SUCCESS;
    }

    /**
     * What the matching could not place, said plainly.
     *
     * A crash that reached no road is not a crash that did not happen, and
     * the share that fell out is the honest measure of how much of the
     * export is actually feeding the scores.
     */
    private function reportUnmatched(int $unplaceable, int $unmatched, int $crashes): void
    {
        if ($unplaceable > 0) {
            $this->components->twoColumnDetail(
                'No usable coordinates',
                $this->formatCount($unplaceable),
            );
        }

        if ($unmatched > 0) {
            $share = round(100 * $unmatched / $crashes, 1);

            $this->components->twoColumnDetail(
                'Placed but no road within '.MatchCrashesToRoadSegments::MATCH_BUFFER_M.' m',
                $this->formatCount($unmatched)." ({$share}%)",
            );
        }
    }

    /**
     * The roads that collected the most crashes, as a sanity check: these
     * should be recognisable main roads, not a footpath or a car park.
     */
    private function reportWorstRoads(): void
    {
        $rows = DB::select(<<<'SQL'
            select
                coalesce(ref, name, '(unnamed)') as road,
                highway_type,
                crash_count,
                round(ST_Length(geom::geography))::int as length_m
            from road_segments
            where crash_count > 0
            order by crash_count desc
            limit 5
        SQL);

        if ($rows === []) {
            return;
        }

        $this->newLine();

        foreach ($rows as $row) {
            $this->components->twoColumnDetail(
                $row->road.' ('.$row->highway_type.')',
                $row->crash_count.' crashes · '.$this->formatCount((int) $row->length_m).' m',
            );
        }
    }

    /**
     * Number::format falls back to false for an unusable locale.
     */
    private function formatCount(int $count): string
    {
        return Number::format($count) ?: (string) $count;
    }
}
