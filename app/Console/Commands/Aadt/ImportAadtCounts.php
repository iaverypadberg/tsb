<?php

namespace App\Console\Commands\Aadt;

use App\Actions\Aadt\AadtSectionData;
use App\Actions\Aadt\FetchAadtSections;
use App\Actions\Aadt\StoreAadtSections;
use App\Models\AadtSection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use RuntimeException;

class ImportAadtCounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aadt:import
        {--prune : Delete sections of an imported year the service no longer publishes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import Vermont AOT annual average daily traffic counts';

    /**
     * How many sections each layer returned, and how many were usable.
     *
     * @var array<int, array{returned: int, usable: int}>
     */
    private array $tally = [];

    /**
     * The sections seen, by year, so `--prune` knows what is still published.
     *
     * @var array<int, list<array{ete_id: string, ete_begin_mm: float, ete_end_mm: float}>>
     */
    private array $seen = [];

    public function handle(FetchAadtSections $fetch, StoreAadtSections $store): int
    {
        $this->components->info('Querying '.config('aadt.service_url'));

        $written = 0;

        try {
            // Pages are written as they arrive: the whole state decoded at
            // once would not fit in a default PHP memory limit.
            foreach ($fetch->handle() as $page) {
                $sections = $this->read($page['layer'], $page['features']);

                $written += $store->handle($sections);
            }
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->reportLayers();

        if ($this->seen === []) {
            $this->components->error('No usable sections came back.');

            return self::FAILURE;
        }

        $this->components->info($this->formatCount($written).' sections written.');

        if ($this->option('prune')) {
            $this->prune($store);
        }

        $this->reportHoldings();

        return self::SUCCESS;
    }

    /**
     * Read one page, keeping count of what had to be dropped.
     *
     * @param  list<array<string, mixed>>  $features
     * @return list<AadtSectionData>
     */
    private function read(int $layerId, array $features): array
    {
        $sections = array_values(array_filter(array_map(
            fn (array $feature): ?AadtSectionData => AadtSectionData::fromFeature($layerId, $feature),
            $features,
        )));

        $this->tally[$layerId] ??= ['returned' => 0, 'usable' => 0];
        $this->tally[$layerId]['returned'] += count($features);
        $this->tally[$layerId]['usable'] += count($sections);

        foreach ($sections as $section) {
            $this->seen[$section->year][] = $section->key();
        }

        return $sections;
    }

    /**
     * Say what each layer gave us, and what had to be dropped.
     *
     * A dropped section is one the service published without geometry or
     * without a count, which is a handful of them every year.
     */
    private function reportLayers(): void
    {
        $this->newLine();

        foreach ($this->tally as $layerId => $counts) {
            $label = match ($layerId) {
                AadtSection::LAYER_LIMITED => 'Limited access',
                AadtSection::LAYER_OTHER => 'Other roads',
                default => "Layer {$layerId}",
            };

            $detail = $this->formatCount($counts['usable']).' sections';
            $dropped = $counts['returned'] - $counts['usable'];

            if ($dropped > 0) {
                $detail .= ' ('.$this->formatCount($dropped).' unusable)';
            }

            $this->components->twoColumnDetail($label, $detail);
        }

        $this->newLine();
    }

    private function prune(StoreAadtSections $store): void
    {
        foreach ($this->seen as $year => $keys) {
            $removed = $store->prune($year, $keys);

            if ($removed > 0) {
                $this->components->warn($this->formatCount($removed)." stale sections removed from {$year}.");
            }
        }
    }

    private function reportHoldings(): void
    {
        $rows = DB::select(<<<'SQL'
            select year, count(*) as sections, round(avg(aadt)) as mean, max(aadt) as busiest
            from aadt_sections
            group by year
            order by year desc
        SQL);

        $this->newLine();

        foreach ($rows as $row) {
            $this->components->twoColumnDetail(
                (string) $row->year,
                $this->formatCount((int) $row->sections).' sections · '
                    .$this->formatCount((int) $row->mean).' mean · '
                    .$this->formatCount((int) $row->busiest).' busiest',
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
