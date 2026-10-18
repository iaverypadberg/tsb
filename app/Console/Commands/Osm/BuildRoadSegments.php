<?php

namespace App\Console\Commands\Osm;

use App\Actions\RoadSegments\InterpretOsmHighway;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class BuildRoadSegments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'osm:build-segments
        {--chunk=2000 : How many staged ways to interpret per batch}
        {--include-service : Keep driveways and parking aisles}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interpret staged OpenStreetMap highways into scored road segments';

    /**
     * Columns read from the staging table. Geometry is deliberately absent:
     * it is copied inside the database rather than pulled through PHP.
     *
     * @var list<string>
     */
    /**
     * How much each point of report severity adds to a road's score.
     */
    private const SEVERITY_WEIGHT = 5;

    /**
     * The most that reports can add, so local knowledge colours the score
     * without completely overriding what the road actually is.
     */
    private const MAX_REPORT_BOOST = 30;

    private const TAG_COLUMNS = [
        'osm_way_id', 'highway', 'ref', 'maxspeed', 'lanes', 'shoulder',
        'cycleway', 'cycleway_left', 'cycleway_right', 'cycleway_both',
        'bicycle', 'surface', 'oneway', 'access', 'service_type',
    ];

    public function handle(InterpretOsmHighway $interpret): int
    {
        $staged = $this->stagedHighways();

        if (($total = $staged->count()) === 0) {
            $this->components->error('No staged highways. Run `php artisan osm:import` first.');

            return self::FAILURE;
        }

        $this->createScoreTable();

        $this->components->info('Interpreting '.$this->formatCount($total).' ways');

        $bar = $this->output->createProgressBar($total);

        $staged->orderBy('osm_way_id')->chunk((int) $this->option('chunk'), function ($ways) use ($interpret, $bar): void {
            $rows = [];

            foreach ($ways as $way) {
                $tags = (array) $way;

                $rows[] = [
                    'osm_way_id' => $way->osm_way_id,
                    ...$interpret->handle($tags),
                ];
            }

            DB::table('osm_segment_scores')->insert($rows);

            $bar->advance(count($rows));
        });

        $bar->finish();
        $this->newLine(2);

        $written = $this->mergeIntoRoadSegments();

        $this->components->info($this->formatCount($written).' road segments written.');

        $this->reportDistribution();

        return self::SUCCESS;
    }

    /**
     * @return Builder
     */
    private function stagedHighways()
    {
        $query = DB::table('osm_highways')->select(self::TAG_COLUMNS);

        if (! $this->option('include-service')) {
            $excluded = config('osm.excluded_service_types');

            $query->where(function ($query) use ($excluded): void {
                $query->whereNull('service_type')
                    ->orWhereNotIn('service_type', $excluded);
            });
        }

        return $query;
    }

    /**
     * A scratch table for the interpreted values, so the geometry can be
     * joined to them in one statement without ever leaving Postgres.
     */
    private function createScoreTable(): void
    {
        DB::statement('drop table if exists osm_segment_scores');

        DB::statement(<<<'SQL'
            create unlogged table osm_segment_scores (
                osm_way_id bigint primary key,
                speed_limit_kmh smallint,
                lanes smallint,
                has_shoulder boolean,
                bike_infra text not null,
                danger_score smallint not null
            )
        SQL);
    }

    /**
     * Upsert rather than replace: `segment_reports` cascade on delete, so
     * recreating segments would throw away hand-recorded local knowledge.
     */
    private function mergeIntoRoadSegments(): int
    {
        $written = DB::affectingStatement(<<<'SQL'
            insert into road_segments (
                osm_way_id, name, ref, geom, highway_type, surface, speed_limit_kmh,
                lanes, has_shoulder, bike_infra, danger_score, source,
                created_at, updated_at
            )
            select
                h.osm_way_id, h.name, h.ref, h.geom, h.highway, h.surface, s.speed_limit_kmh,
                s.lanes, s.has_shoulder, s.bike_infra,
                least(100, s.danger_score + coalesce(r.boost, 0)), 'osm',
                now(), now()
            from osm_segment_scores s
            join osm_highways h using (osm_way_id)
            left join (
                -- Local knowledge recorded against a road raises its score
                -- on top of whatever the OpenStreetMap tags suggest.
                select existing.osm_way_id, least(?, sum(reports.severity) * ?) as boost
                from segment_reports reports
                join road_segments existing on existing.id = reports.road_segment_id
                where existing.osm_way_id is not null
                group by existing.osm_way_id
            ) r on r.osm_way_id = h.osm_way_id
            on conflict (osm_way_id) do update set
                name = excluded.name,
                ref = excluded.ref,
                surface = excluded.surface,
                geom = excluded.geom,
                highway_type = excluded.highway_type,
                speed_limit_kmh = excluded.speed_limit_kmh,
                lanes = excluded.lanes,
                has_shoulder = excluded.has_shoulder,
                bike_infra = excluded.bike_infra,
                danger_score = excluded.danger_score,
                updated_at = now()
        SQL, [self::MAX_REPORT_BOOST, self::SEVERITY_WEIGHT]);

        DB::statement('drop table if exists osm_segment_scores');

        return $written;
    }

    /**
     * Number::format falls back to false for an unusable locale.
     */
    private function formatCount(int $count): string
    {
        return Number::format($count) ?: (string) $count;
    }

    private function reportDistribution(): void
    {
        $buckets = DB::select(<<<'SQL'
            select width_bucket(danger_score, 0, 100, 5) as bucket, count(*) as total
            from road_segments
            where source = 'osm'
            group by bucket
            order by bucket
        SQL);

        $labels = [1 => '0-19 calm', 2 => '20-39', 3 => '40-59', 4 => '60-79', 5 => '80-100 avoid', 6 => '100'];

        $this->newLine();

        foreach ($buckets as $bucket) {
            $this->components->twoColumnDetail(
                $labels[$bucket->bucket] ?? (string) $bucket->bucket,
                $this->formatCount((int) $bucket->total),
            );
        }
    }
}
