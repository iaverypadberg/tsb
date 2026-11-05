<?php

namespace App\Console\Commands\Crashes;

use App\Actions\Crashes\CrashData;
use App\Actions\Crashes\ReadCrashCsv;
use App\Actions\Crashes\StoreCrashes;
use App\Models\Crash;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use RuntimeException;

class ImportCrashes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crashes:import
        {path : A crash export CSV, or a directory of them}
        {--fresh : Clear the crashes already held before importing}
        {--chunk=2000 : How many rows to parse before writing a batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import Vermont crash exports into the crashes table';

    /**
     * An export this size is almost certainly the published per-request cap
     * rather than the whole history, and importing it as though it were the
     * record would understate every road that is not in it.
     */
    private const SUSPICIOUS_ROW_COUNT = 2000;

    /**
     * How far below the busiest season's daily rate a year may fall before
     * it is called thin.
     *
     * A year can be short without its dates showing it. The export cap can
     * land across a whole season rather than clipping the end of it, which
     * leaves the first and last crash months apart and the year looking
     * complete while most of it is missing. Comparing each year's crashes
     * per day against the best covered year is what actually catches that.
     */
    private const THIN_SEASON_SHARE = 0.7;

    public function handle(ReadCrashCsv $read, StoreCrashes $store): int
    {
        $argument = $this->argument('path');
        $path = is_string($argument) ? $argument : '';

        $files = $this->files($path);

        if ($files === []) {
            $this->components->error("No CSV files to import at {$path}.");

            return self::FAILURE;
        }

        $this->components->info(
            count($files) === 1
                ? 'Importing crashes from '.basename($files[0])
                : 'Importing crashes from '.count($files).' files',
        );

        if ($this->option('fresh')) {
            $cleared = Crash::query()->delete();

            $this->components->twoColumnDetail(
                'Cleared first',
                $this->formatCount($cleared).' crashes already held',
            );
        }

        $totals = ['rows' => 0, 'written' => 0, 'unplaceable' => 0, 'unreadable' => 0];
        $truncated = [];

        foreach ($files as $file) {
            $result = $this->importFile($read, $store, $file);

            if ($result === null) {
                return self::FAILURE;
            }

            foreach ($totals as $key => $total) {
                $totals[$key] = $total + $result[$key];
            }

            if ($result['rows'] === self::SUSPICIOUS_ROW_COUNT) {
                $truncated[] = basename($file);
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Files imported', (string) count($files));
        $this->components->twoColumnDetail('Rows read', $this->formatCount($totals['rows']));
        $this->components->twoColumnDetail('Crashes held', $this->formatCount(Crash::count()));

        if ($totals['unplaceable'] > 0) {
            $this->components->twoColumnDetail(
                'Without usable coordinates',
                $this->formatCount($totals['unplaceable']).' kept, cannot be matched',
            );
        }

        if ($totals['unreadable'] > 0) {
            $this->components->twoColumnDetail(
                'Skipped',
                $this->formatCount($totals['unreadable']).' rows with no ObjectID',
            );
        }

        $this->warnAboutTruncation($truncated);
        $this->reportCoverage();

        $this->newLine();
        $this->components->info('Run `php artisan crashes:match` to put these on the road network.');

        return self::SUCCESS;
    }

    /**
     * Import one file, or nothing when it could not be read.
     *
     * @return array{rows: int, written: int, unplaceable: int, unreadable: int}|null
     */
    private function importFile(ReadCrashCsv $read, StoreCrashes $store, string $file): ?array
    {
        $chunk = max(1, (int) $this->option('chunk'));

        $batch = [];
        $rows = $written = $unreadable = $unplaceable = 0;

        try {
            foreach ($read->handle($file) as $row) {
                $rows++;
                $crash = CrashData::fromRow($row);

                if ($crash === null) {
                    $unreadable++;

                    continue;
                }

                if (! $crash->isPlaceable()) {
                    $unplaceable++;
                }

                $batch[] = $crash;

                if (count($batch) >= $chunk) {
                    $written += $store->handle($batch);
                    $batch = [];
                }
            }
        } catch (RuntimeException $exception) {
            $this->components->error(basename($file).': '.$exception->getMessage());

            return null;
        }

        $written += $store->handle($batch);

        $this->components->twoColumnDetail(
            basename($file),
            $this->formatCount($rows).' rows',
        );

        return compact('rows', 'written', 'unplaceable', 'unreadable');
    }

    /**
     * The CSV files to import, whether a file or a directory was named.
     *
     * Sorted, so a directory of one file per year is imported oldest first
     * and the output reads in the order somebody would expect.
     *
     * @return list<string>
     */
    private function files(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (! is_dir($path)) {
            return [];
        }

        $entries = scandir($path);

        if ($entries === false) {
            return [];
        }

        $found = [];

        foreach ($entries as $entry) {
            $file = rtrim($path, '/').'/'.$entry;

            // GLOB_BRACE is not available in every PHP build, so the
            // extension is matched here rather than in a glob pattern.
            if (is_file($file) && strcasecmp(pathinfo($entry, PATHINFO_EXTENSION), 'csv') === 0) {
                $found[] = $file;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * What the imported crashes actually cover, per year.
     *
     * The exports are seasonal and capped, so the window each year really
     * holds is the thing that decides whether a crash rate means anything.
     * It is printed rather than assumed.
     *
     * Crashes per day is printed beside the dates because the dates alone
     * are not enough: a capped export can span a full season and still hold
     * a fraction of it, and only the rate gives that away.
     */
    private function reportCoverage(): void
    {
        $rows = DB::select(<<<'SQL'
            select
                season.*,
                -- The best covered year, carried on every row, so the
                -- comparison does not need a second pass in PHP.
                max(season.per_day) over () as busiest
            from (
                select
                    extract(year from occurred_at)::int as year,
                    count(*) as crashes,
                    min(occurred_at)::date as first_day,
                    max(occurred_at)::date as last_day,
                    (max(occurred_at)::date - min(occurred_at)::date + 1) as days,
                    count(*)::numeric / (max(occurred_at)::date - min(occurred_at)::date + 1) as per_day
                from crashes
                where occurred_at is not null
                group by 1
            ) season
            order by season.year
        SQL);

        if ($rows === []) {
            return;
        }

        $busiest = (float) $rows[0]->busiest;

        $this->newLine();
        $this->components->info('What each year actually covers');

        $thin = [];

        foreach ($rows as $row) {
            $perDay = (float) $row->per_day;
            $share = $busiest > 0 ? $perDay / $busiest : 1.0;

            if ($share < self::THIN_SEASON_SHARE) {
                $thin[] = (int) $row->year;
            }

            $this->components->twoColumnDetail(
                (string) $row->year.($share < self::THIN_SEASON_SHARE ? ' (thin)' : ''),
                $this->formatCount((int) $row->crashes).' crashes · '
                .$row->first_day.' to '.$row->last_day.' ('.(int) $row->days.' days) · '
                .number_format($perDay, 1).'/day',
            );
        }

        $this->warnAboutThinSeasons($thin, $busiest);
    }

    /**
     * Say which years hold far fewer crashes per day than the best covered
     * one, and why that is not the same as those years being safer.
     *
     * The crash term in the danger score divides by the days observed, so a
     * year that holds half its crashes over a full span does not read as
     * incomplete. It reads as a quiet year, and every road whose crashes
     * fell in it is scored as safer than it is.
     *
     * @param  list<int>  $thin
     */
    private function warnAboutThinSeasons(array $thin, float $busiest): void
    {
        if ($thin === []) {
            return;
        }

        $this->newLine();
        $this->components->warn(
            implode(', ', $thin).' hold well under the '.number_format($busiest, 1).' crashes a day the '
            .'best covered year reaches, despite spanning a full season. That is the export cap landing '
            .'across the whole year instead of clipping the end of it, so the dates look healthy while '
            .'most of the crashes are missing. Re-export those years with paging: until then the roads '
            .'whose crashes fell in them are scored as safer than they are.'
        );
    }

    /**
     * The crash data is published through a service that caps how many
     * records one request returns. A file landing exactly on that cap has
     * almost certainly been cut off, and the rest of its year is missing.
     *
     * This matters more than it looks: the files are seasonal, so a year cut
     * off in July is missing the whole of the late season rather than a
     * random slice, and every road whose crashes fell in those months reads
     * as safer than it is.
     *
     * @param  list<string>  $truncated
     */
    private function warnAboutTruncation(array $truncated): void
    {
        if ($truncated === []) {
            return;
        }

        $cap = self::SUSPICIOUS_ROW_COUNT;

        $this->newLine();
        $this->components->warn(
            count($truncated).' of these files hold exactly '.$cap.' rows, which is the export cap rather '
            .'than a number the data would land on by chance: '.implode(', ', $truncated).'. '
            .'Check the dates above — a year that stops short of 30 November was cut off mid-season, and '
            .'the roads whose crashes fell after that point will look safer than they are. Re-export with '
            .'paging to fix it.'
        );
    }

    /**
     * Number::format falls back to false for an unusable locale.
     */
    private function formatCount(int $count): string
    {
        return Number::format($count) ?: (string) $count;
    }
}
