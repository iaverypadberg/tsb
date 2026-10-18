<?php

namespace App\Console\Commands\Osm;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class ImportOsmExtract extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'osm:import
        {source? : A .osm.pbf URL or local path. Defaults to config("osm.extract_url")}
        {--fresh : Download again even if the extract is already on disk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Load OpenStreetMap highways for a region into the osm_highways staging table';

    public function handle(): int
    {
        $source = $this->argument('source') ?? config('osm.extract_url');

        $extract = Str::startsWith($source, ['http://', 'https://'])
            ? $this->download($source)
            : realpath($source);

        if ($extract === false || ! is_file($extract)) {
            $this->components->error("No extract found at [{$source}].");

            return self::FAILURE;
        }

        $this->components->info('Importing '.basename($extract).' ('.$this->describeSize($extract).')');

        if (! $this->runOsm2pgsql($extract)) {
            return self::FAILURE;
        }

        $this->report();

        return self::SUCCESS;
    }

    /**
     * Fetch the extract, reusing an earlier download when there is one.
     */
    private function download(string $url): string
    {
        $directory = config('osm.extract_path');

        File::ensureDirectoryExists($directory);

        $destination = $directory.'/'.basename(parse_url($url, PHP_URL_PATH) ?: 'extract.osm.pbf');

        if (is_file($destination) && ! $this->option('fresh')) {
            $this->components->twoColumnDetail(
                'Using cached extract',
                $this->describeSize($destination),
            );

            return $destination;
        }

        $this->components->info("Downloading {$url}");

        // Streamed to disk: these extracts do not belong in memory.
        $response = Http::timeout(600)->sink($destination)->get($url);

        if ($response->failed()) {
            File::delete($destination);

            throw new \RuntimeException("Download failed with status {$response->status()}.");
        }

        return $destination;
    }

    /**
     * Run osm2pgsql, preferring a local binary and falling back to the
     * container defined under the compose `tools` profile.
     */
    private function runOsm2pgsql(string $extract): bool
    {
        $local = $this->hasLocalBinary();

        $command = $local
            ? $this->localCommand($extract)
            : $this->containerCommand($extract);

        $this->components->twoColumnDetail(
            'Running osm2pgsql',
            $local ? 'local binary' : 'docker compose',
        );

        $result = Process::forever()
            ->env(['PGPASSWORD' => (string) config('database.connections.pgsql.password')])
            ->run($command, function (string $type, string $output): void {
                $this->output->write($output);
            });

        if ($result->failed()) {
            $this->components->error('osm2pgsql failed. See the output above.');

            return false;
        }

        return true;
    }

    private function hasLocalBinary(): bool
    {
        return Process::run(['sh', '-c', 'command -v osm2pgsql'])->successful();
    }

    /**
     * @return list<string>
     */
    private function localCommand(string $extract): array
    {
        return [
            'osm2pgsql',
            '--output=flex',
            '--style='.base_path('database/osm/highways.lua'),
            ...$this->connectionArguments((string) config('database.connections.pgsql.host')),
            $extract,
        ];
    }

    /**
     * The extract directory is bind-mounted at /pbf and the style at /osm.
     *
     * @return list<string>
     */
    private function containerCommand(string $extract): array
    {
        return [
            'docker', 'compose', '--profile', 'tools', 'run', '--rm',
            config('osm.compose_service'),
            'osm2pgsql',
            '--output=flex',
            '--style=/osm/highways.lua',
            ...$this->connectionArguments((string) config('osm.compose_db_host')),
            '/pbf/'.basename($extract),
        ];
    }

    /**
     * @return list<string>
     */
    private function connectionArguments(string $host): array
    {
        return [
            '--host='.$host,
            '--port='.config('database.connections.pgsql.port'),
            '--database='.config('database.connections.pgsql.database'),
            '--user='.config('database.connections.pgsql.username'),
        ];
    }

    /**
     * A human readable file size, which is only ever used for display.
     */
    private function describeSize(string $path): string
    {
        $bytes = filesize($path);

        if ($bytes === false) {
            return 'size unknown';
        }

        return Number::fileSize($bytes) ?: $bytes.' B';
    }

    /**
     * Number::format falls back to false for an unusable locale.
     */
    private function formatCount(int $count): string
    {
        return Number::format($count) ?: (string) $count;
    }

    /**
     * Summarise what landed in the staging table.
     */
    private function report(): void
    {
        $total = DB::table('osm_highways')->count();

        $this->newLine();
        $this->components->info($this->formatCount($total).' highway ways staged.');

        $byType = DB::table('osm_highways')
            ->selectRaw('highway, count(*) as total')
            ->groupBy('highway')
            ->orderByDesc('total')
            ->get();

        foreach ($byType as $row) {
            $this->components->twoColumnDetail($row->highway, $this->formatCount((int) $row->total));
        }
    }
}
