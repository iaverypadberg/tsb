<?php

namespace App\Console\Commands\Roads;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildRoadData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'roads:rebuild
        {artifact? : A local artifact path. Omit to pull the newest published release}
        {--release= : Load this release tag instead of the newest}
        {--skip-load : Rebuild from whatever is already staged}
        {--with-imports : Refresh the traffic counts and crash records too}
        {--crashes=crashes : The directory of crash export CSVs, used by --with-imports}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Load the published highway artifact and rebuild every derived road table';

    /**
     * Rebuilding segments replaces segment geometry, so both matches have to
     * run after it or they would be scoring geometry that is gone. That
     * ordering is the whole reason this command exists rather than a note
     * telling someone to run four commands in the right order.
     */
    public function handle(): int
    {
        foreach ($this->steps() as $description => $step) {
            if ($this->shouldSkip($step)) {
                $this->newLine();
                $this->components->warn(
                    "Skipping: {$description} — {$step['requires']} holds nothing to match. "
                    .'Re-run with --with-imports to fetch it.',
                );

                continue;
            }

            $this->newLine();
            $this->components->info($description);

            if ($this->call($step['command'], $step['arguments']) !== self::SUCCESS) {
                $this->newLine();
                $this->components->error("Stopped at: {$description}");

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->components->info('Road data rebuilt.');

        $this->reportHoldings();

        return self::SUCCESS;
    }

    /**
     * The rebuild, in the order it has to happen.
     *
     * A match whose source table is empty is skipped rather than run,
     * because `aadt:match` and `crashes:match` both fail outright on an
     * empty source. Failing there would abandon the rebuild half-done, with
     * segments already replaced, which is worse than a rebuild that says
     * plainly which scores it could not apply.
     *
     * @return array<string, array{command: string, arguments: array<string, mixed>, requires: string|null}>
     */
    private function steps(): array
    {
        $steps = [];

        if (! $this->option('skip-load')) {
            $steps['Loading the highway artifact'] = $this->step('osm:load', array_filter([
                'artifact' => $this->argument('artifact'),
                '--release' => $this->option('release'),
            ]));
        }

        $steps['Interpreting staged ways into road segments'] = $this->step('osm:build-segments');

        if ($this->option('with-imports')) {
            $steps['Importing traffic counts'] = $this->step('aadt:import');
        }

        $steps['Matching traffic counts to segments'] = $this->step(
            'aadt:match', requires: 'aadt_sections',
        );

        if ($this->option('with-imports')) {
            $steps['Importing crash records'] = $this->step('crashes:import', [
                'path' => $this->option('crashes'),
            ]);
        }

        $steps['Matching crashes to segments'] = $this->step(
            'crashes:match', requires: 'crashes',
        );

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{command: string, arguments: array<string, mixed>, requires: string|null}
     */
    private function step(string $command, array $arguments = [], ?string $requires = null): array
    {
        return ['command' => $command, 'arguments' => $arguments, 'requires' => $requires];
    }

    /**
     * @param  array{command: string, arguments: array<string, mixed>, requires: string|null}  $step
     */
    private function shouldSkip(array $step): bool
    {
        return $step['requires'] !== null
            && DB::table($step['requires'])->doesntExist();
    }

    /**
     * What the rebuild left behind, so an empty table is visible rather than
     * something to discover later on the dashboard.
     */
    private function reportHoldings(): void
    {
        $this->newLine();

        foreach (['osm_highways', 'road_segments', 'aadt_sections', 'crashes'] as $table) {
            $this->components->twoColumnDetail(
                $table,
                number_format(DB::table($table)->count()).' rows',
            );
        }
    }
}
