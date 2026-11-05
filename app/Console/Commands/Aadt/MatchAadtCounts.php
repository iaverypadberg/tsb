<?php

namespace App\Console\Commands\Aadt;

use App\Actions\Aadt\MatchAadtToRoadSegments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class MatchAadtCounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aadt:match
        {--year= : Match against this year of counts instead of the most recent}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Work out how much traffic each road segment carries from the published counts';

    /**
     * What route number agreement this matching reaches when it is working.
     *
     * Measured over the 2025 counts against a Vermont extract: 99.9% of the
     * samples that can be compared at all. It is recorded here so a run that
     * has quietly drifted says so, instead of the figure having to be
     * remembered by whoever reads the output.
     */
    private const EXPECTED_AGREEMENT_PCT = 99.0;

    public function handle(MatchAadtToRoadSegments $matcher): int
    {
        $year = $this->option('year') === null ? null : (int) $this->option('year');

        $this->components->info('Matching road segments against the traffic counts');

        $result = $matcher->handle($year);

        if ($result['year'] === 0) {
            $this->components->error('No counts have been imported. Run `php artisan aadt:import` first.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Year', (string) $result['year']);
        $this->components->twoColumnDetail('Roads measured', $this->formatCount($result['roads_measured']));
        $this->components->twoColumnDetail('Section matches', $this->formatCount($result['matches']));
        $this->components->twoColumnDetail('Roads rescored', $this->formatCount($result['rescored']));

        $this->reportAgreement($result['compared'], $result['agreeing'], $result['unchecked']);
        $this->reportRejections($result['wrong_route'], $result['rivalled']);
        $this->reportCoverage();

        return self::SUCCESS;
    }

    /**
     * How often the count a road was given belongs to the route the road
     * says it is on.
     *
     * This is the only independent check on the geometry, so it is printed
     * every run rather than kept for a test: a sharp fall means the matching
     * has drifted, whatever the road totals look like.
     */
    private function reportAgreement(int $compared, int $agreeing, int $unchecked): void
    {
        if ($compared === 0) {
            return;
        }

        $share = round(100 * $agreeing / $compared, 1);

        $this->components->twoColumnDetail(
            'Route number agreement',
            "{$share}% of ".$this->formatCount($compared).' numbered samples',
        );

        // Town highways have no number on either side to compare, so they are
        // said separately rather than folded into the figure above.
        $this->components->twoColumnDetail(
            'Unnumbered samples',
            $this->formatCount($unchecked).' not checkable',
        );

        if ($share < self::EXPECTED_AGREEMENT_PCT) {
            $this->components->warn(
                'Agreement is below the '.self::EXPECTED_AGREEMENT_PCT.'% this matching normally reaches. '
                .'Something has drifted: check the buffer and bearing limits before trusting these counts.'
            );
        }
    }

    /**
     * What the geometry offered and the rest of the matching turned down.
     *
     * A sample is refused either because the count is of a numbered route
     * the road is not on, or because a road nearer the section is the one it
     * was drawn for. Both are counts that used to land on whatever ran
     * alongside a highway, so the figures are the size of the correction
     * rather than a fault: on the 2025 counts they are what keeps US 2 off
     * the bike path beside it.
     */
    private function reportRejections(int $wrongRoute, int $rivalled): void
    {
        if ($wrongRoute === 0 && $rivalled === 0) {
            return;
        }

        $this->components->twoColumnDetail(
            'Refused as another route\'s',
            $this->formatCount($wrongRoute).' samples',
        );

        $this->components->twoColumnDetail(
            'Refused to a nearer road',
            $this->formatCount($rivalled).' samples',
        );
    }

    /**
     * What share of each kind of road ended up with a count, by length.
     *
     * Coverage is partial by design, and this is the honest picture of how
     * much of the network the counts can actually speak for.
     */
    private function reportCoverage(): void
    {
        $rows = DB::select(<<<'SQL'
            select
                highway_type,
                round(sum(ST_Length(geom::geography)) / 1000) as km,
                round(100.0 * sum(case when aadt is not null then ST_Length(geom::geography) else 0 end)
                    / nullif(sum(ST_Length(geom::geography)), 0)) as measured_pct
            from road_segments
            where highway_type = any('{motorway,trunk,primary,secondary,tertiary,unclassified,residential}'::text[])
            group by highway_type
            order by km desc
        SQL);

        $this->newLine();

        foreach ($rows as $row) {
            $this->components->twoColumnDetail(
                $row->highway_type,
                $this->formatCount((int) $row->km).' km · '.(int) $row->measured_pct.'% measured',
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
