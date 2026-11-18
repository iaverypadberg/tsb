<?php

namespace App\Console\Commands\Osm;

use App\Actions\Osm\ExportStagedHighways;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use RuntimeException;

class ExportHighwayArtifact extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'osm:export
        {--path= : Where to write the artifact. Defaults to the configured artifact path}
        {--source= : What the ways were imported from, recorded in the artifact header}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Write the staged OpenStreetMap highways out as a loadable artifact';

    public function handle(ExportStagedHighways $export): int
    {
        $path = $this->stringOption('path') ?? $this->defaultPath();

        $source = $this->stringOption('source') ?? basename(
            (string) parse_url((string) config('osm.extract_url'), PHP_URL_PATH),
        );

        $this->components->info("Exporting to {$path}");

        $bar = $this->output->createProgressBar();

        try {
            $result = $export->handle($path, $source, fn (int $rows) => $bar->setProgress($rows));
        } catch (RuntimeException $exception) {
            $bar->clear();

            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->components->twoColumnDetail('Ways', $this->formatCount($result['rows']));
        $this->components->twoColumnDetail('Size', $this->describeSize($result['bytes']));
        $this->components->twoColumnDetail('Source', $source);

        $this->newLine();
        $this->components->info('Artifact written.');

        return self::SUCCESS;
    }

    /**
     * A value option as a string, treating one passed empty as absent so the
     * caller's default applies.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function defaultPath(): string
    {
        return rtrim((string) config('osm.artifact.path'), '/')
            .'/'.config('osm.artifact.name');
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
