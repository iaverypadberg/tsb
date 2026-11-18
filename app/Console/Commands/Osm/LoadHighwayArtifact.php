<?php

namespace App\Console\Commands\Osm;

use App\Actions\Osm\FetchHighwayArtifact;
use App\Actions\Osm\LoadStagedHighways;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use RuntimeException;

class LoadHighwayArtifact extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'osm:load
        {artifact? : A local artifact path. Omit to pull the newest published release}
        {--release= : Load this release tag instead of the newest}
        {--dry-run : Read and check the artifact without writing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Load a published highway artifact into the staging table';

    public function handle(FetchHighwayArtifact $fetch, LoadStagedHighways $load): int
    {
        try {
            $path = $this->resolve($fetch);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return $this->option('dry-run')
            ? $this->check($load, $path)
            : $this->load($load, $path);
    }

    /**
     * Either the path given, or the artifact pulled from a release.
     */
    private function resolve(FetchHighwayArtifact $fetch): string
    {
        $local = $this->stringArgument('artifact');

        if ($local !== null) {
            return $local;
        }

        $this->components->info('Looking for a published artifact');

        $fetched = $fetch->handle(
            $this->stringOption('release'),
            fn (string $what) => $this->components->twoColumnDetail('Downloading', $what),
        );

        $this->components->twoColumnDetail('Size', $this->describeSize($fetched['bytes']));

        return $fetched['path'];
    }

    private function check(LoadStagedHighways $load, string $path): int
    {
        try {
            $result = $load->inspect($path);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->describeHeader($result['header']);

        $this->newLine();
        $this->components->info(
            $this->formatCount($result['rows']).' ways read and checked. Nothing was written.',
        );

        return self::SUCCESS;
    }

    private function load(LoadStagedHighways $load, string $path): int
    {
        $this->newLine();
        $this->components->info('Loading into osm_highways');

        $bar = $this->output->createProgressBar();

        try {
            $result = $load->handle($path, fn (int $rows) => $bar->setProgress($rows));
        } catch (RuntimeException $exception) {
            $bar->clear();

            $this->components->error($exception->getMessage());
            $this->components->warn('The staging table was left as it was.');

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->describeHeader($result['header']);

        $this->newLine();
        $this->components->info($this->formatCount($result['rows']).' ways staged.');

        return self::SUCCESS;
    }

    /**
     * @param  array{format: int, generated_at: string, rows: int, source: string, columns: list<string>}  $header
     */
    private function describeHeader(array $header): void
    {
        $this->components->twoColumnDetail('Built from', $header['source']);
        $this->components->twoColumnDetail(
            'Built at',
            Carbon::parse($header['generated_at'])->toDayDateTimeString(),
        );
    }

    /**
     * A value option as a string, treating one passed empty as absent so the
     * newest release is used instead.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function describeSize(int $bytes): string
    {
        return Number::fileSize($bytes) ?: $bytes.' B';
    }

    /**
     * Number::format falls back to false for an unusable locale.
     */
    private function formatCount(int $count): string
    {
        return Number::format($count) ?: (string) $count;
    }
}
