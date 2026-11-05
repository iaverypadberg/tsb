<?php

namespace App\Actions\Aadt;

use App\Actions\RoadSegments\RecalculateDangerScores;
use App\Models\AadtSection;
use Illuminate\Support\Facades\DB;

/**
 * Works out how much traffic each road carries, from the published counts.
 *
 * The two datasets describe the same roads but agree on nothing else: VTrans
 * locates a count along its own linear referencing system and breaks its
 * sections at mileposts, while OpenStreetMap breaks ways wherever a tag
 * changes. Neither identifiers nor milepost measures carry across, so the
 * geometry is what joins them.
 *
 * A road name cannot do the job. US 2 is one name over 97 published sections
 * running from 44 to 47,635 vehicles a day, so the name says which road a
 * rider is on and nothing at all about how busy the part under them is.
 *
 * So each way is sampled along its length and every sample is matched to the
 * nearest section whose bearing agrees, which is the same approach
 * `MatchRouteToRoadSegments` takes to put a route on the road network.
 *
 * ## Why distance and bearing are not enough
 *
 * A section is drawn along a route's centreline, so the way carrying the
 * traffic is already ten metres off it and the buffer has to be loose enough
 * to reach. Anything else lying inside that buffer on the same bearing is
 * then just as eligible, and beside a busy road there usually is something:
 * a separated bike path, a rail trail, a frontage road, a parking aisle, the
 * old alignment, the street behind. Measured against the 2025 counts the
 * matcher gave 306 `cycleway` segments a median of 11,455 vehicles a day,
 * which is US 2 read off verbatim rather than anything on the path.
 *
 * Three further tests say whose count it is:
 *
 *  - only roads the count could be of are candidates at all. VTrans counts
 *    motor traffic on the public highway network, so it publishes nothing
 *    for a cycleway, a footpath, a track or a service road, and a figure
 *    that lands on one came off the road beside it.
 *    {@see self::NOT_ON_COUNTED_NETWORK}
 *  - a numbered section's count only goes to a road that plausibly carries
 *    that number: one tagged with it, or an unnumbered through road. A
 *    residential street is not US 7 however close to it it runs.
 *    {@see self::THROUGH_ROUTE_CLASSES}
 *  - of two roads running alongside a section, the count belongs to the one
 *    the section was drawn down. A rival road that is clearly closer takes
 *    the count away from the one that found it. {@see self::RIVAL_MARGIN_M}
 *
 * Everything happens inside Postgres against projected scratch tables. The
 * counts are published in Vermont State Plane, and working in it means
 * distances are already metres; transforming geometry inside the join
 * instead would make the spatial index useless and the query unusable.
 */
class MatchAadtToRoadSegments
{
    public function __construct(private RecalculateDangerScores $recalculate) {}

    /**
     * Vermont State Plane, in metres: what VTrans publishes in.
     */
    public const PROJECTED_SRID = 32145;

    /**
     * How far apart samples are taken along a road, in metres.
     */
    public const SAMPLE_INTERVAL_M = 25;

    /**
     * How far from a count a sample may sit and still be counted as on it.
     *
     * Looser than the 15 m the route matcher allows, because a published
     * section is drawn along a route's centreline rather than along either
     * carriageway.
     */
    public const MATCH_BUFFER_M = 20;

    /**
     * How far a section's bearing may differ from the road's, in degrees.
     * Compared modulo 180, since a road counted the other way round is still
     * the same road.
     */
    public const MAX_BEARING_DELTA_DEGREES = 30;

    /**
     * Bearings are taken over this many metres of line either side of a
     * point, which smooths out the jitter of individual vertices.
     */
    private const BEARING_WINDOW_M = 10.0;

    /**
     * How much of a road has to have matched before its count is believed.
     *
     * Below this the road is left unmeasured rather than given a figure from
     * the fraction of it that happened to run alongside a counted section.
     */
    public const MIN_SAMPLE_SHARE = 0.6;

    /**
     * Section kinds that are counted but cannot be ridden.
     *
     * @var list<string>
     */
    public const EXCLUDED_ROUTE_TYPES = [AadtSection::ROUTE_TYPE_RAMP];

    /**
     * Ways that are never what a published count is a count of.
     *
     * VTrans counts motor traffic on the public highway network: the
     * interstates, US and VT highways, federal urban routes and the larger
     * collectors. It publishes no figure for a bike path, a footway, a farm
     * track, a driveway or a parking aisle, so a count that lands on one of
     * those is the neighbouring road's, borrowed by being within twenty
     * metres of its centreline.
     *
     * Leaving them out is not only a correction to the geometry. A rider on
     * a path physically separated from a road is not in that road's traffic,
     * so there is no figure here that would be right for them, and `null`
     * reads as "not counted" rather than as a claim about the path.
     *
     * Residential and unclassified streets stay eligible: a counted town
     * highway is usually tagged as one of those, so they are the roads most
     * of the non-numbered sections belong to.
     *
     * @var list<string>
     */
    public const NOT_ON_COUNTED_NETWORK = [
        'cycleway', 'footway', 'path', 'bridleway', 'steps', 'pedestrian',
        'track', 'service', 'construction', 'proposed',
    ];

    /**
     * Classes an unnumbered way may still take a numbered route's count.
     *
     * OpenStreetMap leaves `ref` off a numbered road often enough that a
     * missing number cannot disqualify a road on its own: 58 primary and 24
     * secondary ways matched a numbered section without carrying its number.
     * What it cannot do is promote a lane. A road tagged `residential` is
     * not US 7, so where the section is numbered and the way neither carries
     * that number nor is drawn as a through road, the count is somebody
     * else's.
     *
     * @var list<string>
     */
    public const THROUGH_ROUTE_CLASSES = [
        'motorway', 'motorway_link', 'trunk', 'trunk_link',
        'primary', 'primary_link', 'secondary', 'secondary_link',
        'tertiary', 'tertiary_link',
    ];

    /**
     * How much closer a rival road has to be, in metres, before the count is
     * taken to be its rather than the road's that found it.
     *
     * A section is drawn down one road's centreline, so that road sits all
     * but on top of it while a path or a back street beside it sits out at
     * the edge of the buffer. In the 2025 counts the mismatched cycleways
     * were 9 to 17 m from the section they took, with the highway it was
     * drawn down 0 to 3 m away, so the gap is large and the margin only has
     * to be larger than the noise.
     *
     * The margin is what keeps a divided highway measured. Where one section
     * covers both carriageways, each sits a few metres off it on opposite
     * sides, and neither is a rival to the other at this distance. The
     * identity test is the backstop for the wider medians: a road is never
     * rivalled by one carrying the same route number or the same name,
     * because that is the same road coming back the other way.
     */
    public const RIVAL_MARGIN_M = 6;

    /**
     * Match every road against one year of counts.
     *
     * @return array{year: int, roads_measured: int, matches: int, compared: int, agreeing: int, unchecked: int, wrong_route: int, rivalled: int, rescored: int}
     */
    public function handle(?int $year = null): array
    {
        $year ??= AadtSection::latestYear();

        if ($year === null) {
            return [
                'year' => 0, 'roads_measured' => 0, 'matches' => 0, 'compared' => 0,
                'agreeing' => 0, 'unchecked' => 0, 'wrong_route' => 0, 'rivalled' => 0,
                'rescored' => 0,
            ];
        }

        $this->projectSections($year);
        $this->projectRoadSegments();
        $this->takeSamples();
        $this->dropRivalledMatches();

        $result = DB::transaction(function () use ($year): array {
            $this->clearPreviousMatches($year);

            return [
                'year' => $year,
                'matches' => $this->recordMatches(),
                'roads_measured' => $this->recordCounts($year),
            ];
        });

        $result = [...$result, ...$this->sampleDiagnostics()];

        // A count that moved changes the danger score of the road under it,
        // and clearing the previous run's figures changes the scores of the
        // roads that no longer match, so the whole network is rescored
        // rather than only the roads measured this time.
        $result['rescored'] = $this->recalculate->handleAll();

        $this->dropScratchTables();

        return $result;
    }

    /**
     * One row per piece of line, rather than per section: a few sections are
     * published in several pieces, and the bearing of a point along a line is
     * only a question you can ask of a single line.
     */
    private function projectSections(int $year): void
    {
        DB::statement('drop table if exists aadt_match_sections');

        $routeKey = $this->routeKeySql('sections.route_num');

        DB::statement(<<<SQL
            create unlogged table aadt_match_sections as
            select
                row_number() over () as piece_id,
                sections.id as aadt_section_id,
                sections.aadt,
                sections.route_num,
                {$routeKey} as route_key,
                piece.geom,
                ST_Length(piece.geom) as length_m
            from aadt_sections sections
            cross join lateral ST_Dump(ST_Transform(sections.geom, ?::int)) as piece
            where sections.year = ?::int
              and (sections.route_type is null or sections.route_type <> all(?::text[]))
        SQL, [self::PROJECTED_SRID, $year, $this->sqlArray(self::EXCLUDED_ROUTE_TYPES)]);

        DB::statement('create index on aadt_match_sections using gist (geom)');
        DB::statement('create index on aadt_match_sections (piece_id)');
        DB::statement('analyze aadt_match_sections');
    }

    /**
     * The roads a count could be a count of, with everything the matching
     * needs to tell one from another worked out once.
     */
    private function projectRoadSegments(): void
    {
        DB::statement('drop table if exists aadt_match_ways');

        $notCounted = $this->sqlArrayLiteral(self::NOT_ON_COUNTED_NETWORK);
        $throughClasses = $this->sqlArrayLiteral(self::THROUGH_ROUTE_CLASSES);
        $buffer = (float) self::MATCH_BUFFER_M;

        DB::statement(<<<SQL
            create unlogged table aadt_match_ways as
            select
                segments.id as road_segment_id,
                segments.ref,
                segments.name,
                keys.route_keys,
                -- A way that says which numbered route it is can be held to
                -- it. One that says nothing is judged on its class instead.
                exists (
                    select 1 from unnest(keys.route_keys) as key where key ~ '^(I|US|VT)[0-9]'
                ) as carries_route_number,
                coalesce(segments.highway_type = any({$throughClasses}), false) as is_through_class,
                projected.geom,
                ST_Length(projected.geom) as length_m
            from road_segments segments
            cross join lateral (select ST_Transform(segments.geom, ?::int) as geom) projected
            cross join lateral (
                select coalesce(array_agg(key), '{}'::text[]) as route_keys
                from (
                    -- A road can carry two numbers at once, which
                    -- OpenStreetMap writes into one tag separated by a
                    -- semicolon. Punctuation differs between the two sources
                    -- ('I-91' against 'I 91'), so both sides are stripped to
                    -- letters and digits before comparing.
                    select regexp_replace(upper(btrim(alternative)), '[^A-Z0-9]', '', 'g') as key
                    from unnest(string_to_array(coalesce(segments.ref, ''), ';')) as alternative
                ) stripped
                where key <> ''
            ) keys
            where ST_Length(projected.geom) > 0
              and (segments.highway_type is null or segments.highway_type <> all({$notCounted}))
              -- Most of the network is residential lanes nobody counts, and
              -- sampling a road no section comes near only to match nothing
              -- is the bulk of the work avoided here.
              and exists (
                select 1
                from aadt_match_sections sections
                where ST_DWithin(sections.geom, projected.geom, {$buffer})
              )
        SQL, [self::PROJECTED_SRID]);

        DB::statement('create index on aadt_match_ways using gist (geom)');
        DB::statement('create index on aadt_match_ways (road_segment_id)');
        DB::statement('analyze aadt_match_ways');
    }

    /**
     * Sample every road and match each sample to a section.
     *
     * Samples are evenly spaced, so each one stands for the same length of
     * road: averaging over them is already a length weighted average, and a
     * short clip of a busy junction cannot relabel a quiet lane.
     *
     * Two sections are found per sample. `match` is the nearest one the road
     * could plausibly be carrying the count of, and is what the road is
     * measured from. `candidate` is the nearest one on bearing alone, which
     * is what the matching would have concluded from the geometry by itself,
     * and is kept only so {@see self::sampleDiagnostics()} can report how
     * often the geometry and the route numbers tell the same story.
     */
    private function takeSamples(): void
    {
        DB::statement('drop table if exists aadt_match_samples');

        $interval = (float) self::SAMPLE_INTERVAL_M;
        $position = 'step.i::float / steps.step_count';
        $roadBearing = $this->bearingAtSql('steps.geom', $position, 'steps.length_m');

        // Only a section the road could be carrying the count of. A section
        // on a numbered route needs the road to carry that number, or to be
        // drawn as a through road and claim no number of its own.
        $plausible = <<<'SQL'
            and (
                sections.route_key is null
                or sections.route_key = any(steps.route_keys)
                or (not steps.carries_route_number and steps.is_through_class)
            )
        SQL;

        $nearestPlausible = $this->nearestSectionSql($plausible);
        $nearestOnBearing = $this->nearestSectionSql('');

        DB::statement(<<<SQL
            create unlogged table aadt_match_samples as
            with steps as (
                select
                    ways.*,
                    greatest(1, (ways.length_m / {$interval})::int) as step_count
                from aadt_match_ways ways
            )
            select
                steps.road_segment_id,
                steps.ref,
                steps.name,
                steps.route_keys,
                sample.point,
                sample.bearing as road_bearing,
                candidate.aadt_section_id as candidate_section_id,
                candidate.route_num as candidate_route_num,
                candidate.route_key as candidate_route_key,
                candidate.route_key = any(steps.route_keys) as candidate_agrees,
                match.piece_id,
                match.aadt_section_id,
                match.aadt,
                match.distance_m,
                false as rivalled
            from steps
            cross join generate_series(0, steps.step_count) as step(i)
            cross join lateral (
                select
                    ST_LineInterpolatePoint(steps.geom, {$position}) as point,
                    {$roadBearing} as bearing
            ) sample
            left join lateral ({$nearestPlausible}) match on true
            left join lateral ({$nearestOnBearing}) candidate on true
        SQL);

        DB::statement('create index on aadt_match_samples (road_segment_id)');
        DB::statement('analyze aadt_match_samples');
    }

    /**
     * The nearest section to a sample whose bearing agrees with the road's.
     *
     * `$restriction` is further SQL narrowing which sections are eligible,
     * so the same query can answer both what the geometry says on its own
     * and what it says once the route numbers are taken into account.
     */
    private function nearestSectionSql(string $restriction): string
    {
        $buffer = (float) self::MATCH_BUFFER_M;
        $sectionBearing = $this->bearingAtSql('sections.geom', 'loc.position', 'sections.length_m');
        $bearingsAgree = $this->bearingsAgreeSql('sample.bearing', 'section.bearing');

        return <<<SQL
            select
                sections.piece_id,
                sections.aadt_section_id,
                sections.aadt,
                sections.route_num,
                sections.route_key,
                ST_Distance(sections.geom, sample.point) as distance_m
            from aadt_match_sections sections
            cross join lateral (
                select ST_LineLocatePoint(sections.geom, sample.point) as position
            ) loc
            cross join lateral (select {$sectionBearing} as bearing) section
            where ST_DWithin(sections.geom, sample.point, {$buffer})
              and {$bearingsAgree}
              {$restriction}
            order by sections.geom <-> sample.point
            limit 1
        SQL;
    }

    /**
     * Take a count back off a road that a nearer road was drawn for.
     *
     * The count is compared where it was measured rather than over the whole
     * section: a section runs for miles, so which road is closest to it is a
     * question that only has an answer at a point. The point used is the one
     * on the section nearest the sample, and a rival has to be closer to
     * that, run along the section like the road does, and be a different
     * road — not the same route number or the same name coming back the
     * other way, which is how a divided highway is drawn.
     */
    private function dropRivalledMatches(): void
    {
        $margin = (float) self::RIVAL_MARGIN_M;
        $rivalBearing = $this->bearingAtSql('rival.geom', 'near.position', 'rival.length_m');
        $runsAlongside = $this->bearingsAgreeSql('samples.road_bearing', 'parallel.bearing');

        DB::statement(<<<SQL
            update aadt_match_samples samples
            set piece_id = null, aadt_section_id = null, aadt = null, rivalled = true
            where samples.aadt_section_id is not null
              and samples.distance_m > {$margin}
              and exists (
                select 1
                from aadt_match_sections sections
                cross join lateral (
                    select ST_ClosestPoint(sections.geom, samples.point) as anchor
                ) closest
                join aadt_match_ways rival
                    on rival.road_segment_id <> samples.road_segment_id
                   and ST_DWithin(rival.geom, closest.anchor, samples.distance_m - {$margin})
                cross join lateral (
                    select ST_LineLocatePoint(rival.geom, closest.anchor) as position
                ) near
                cross join lateral (select {$rivalBearing} as bearing) parallel
                where sections.piece_id = samples.piece_id
                  and {$runsAlongside}
                  and not (rival.route_keys && samples.route_keys)
                  and not (
                    rival.name is not null
                    and samples.name is not null
                    and lower(rival.name) = lower(samples.name)
                  )
              )
        SQL);
    }

    /**
     * Clear what an earlier run concluded, so a road that no longer matches
     * loses its count rather than keeping a figure nothing supports.
     */
    private function clearPreviousMatches(int $year): void
    {
        DB::statement(<<<'SQL'
            delete from aadt_section_road_segment matches
            using aadt_sections sections
            where sections.id = matches.aadt_section_id
              and sections.year = ?::int
        SQL, [$year]);

        DB::statement(<<<'SQL'
            update road_segments
            set aadt = null, aadt_year = null, aadt_sample_share = null, updated_at = now()
            where aadt_year = ?::int
        SQL, [$year]);
    }

    /**
     * Record which sections fed which road, and over how many samples.
     */
    private function recordMatches(): int
    {
        return DB::affectingStatement(<<<'SQL'
            insert into aadt_section_road_segment (
                aadt_section_id, road_segment_id, samples, created_at, updated_at
            )
            select aadt_section_id, road_segment_id, count(*), now(), now()
            from aadt_match_samples
            where aadt_section_id is not null
            group by aadt_section_id, road_segment_id
            on conflict (aadt_section_id, road_segment_id) do update set
                samples = excluded.samples,
                updated_at = now()
        SQL);
    }

    /**
     * Write the count onto each road that matched enough of its length.
     *
     * A sample a rival road took the count off counts against the share,
     * which is the point of it: a road that only matched because it ran
     * beside something busier ends up unmeasured rather than half measured.
     */
    private function recordCounts(int $year): int
    {
        return DB::affectingStatement(<<<'SQL'
            update road_segments segments
            set aadt = measured.aadt,
                aadt_year = ?::int,
                aadt_sample_share = measured.sample_share,
                updated_at = now()
            from (
                select
                    road_segment_id,
                    round(avg(aadt) filter (where aadt_section_id is not null)) as aadt,
                    count(aadt_section_id)::numeric / count(*) as sample_share
                from aadt_match_samples
                group by road_segment_id
            ) measured
            where segments.id = measured.road_segment_id
              and measured.sample_share >= ?::numeric
              and measured.aadt is not null
        SQL, [$year, self::MIN_SAMPLE_SHARE]);
    }

    /**
     * What the run had to throw out, and whether the geometry and the route
     * numbers agreed about what was left.
     *
     * The agreement figure is taken over the nearest section on bearing
     * alone, not over the sections actually used: a road is now only given a
     * count it could plausibly be carrying, so measuring agreement over the
     * matches would be measuring the filter rather than the geometry. Read
     * this way it stays what it always was, the one independent check on
     * whether the geometry is putting counts on the right roads.
     *
     * Only numbered routes are checkable. A town highway carries a code of
     * VTrans' own (`S3004`, `FAU3030`) that OpenStreetMap has no equivalent
     * for, so those samples are counted as unchecked rather than as
     * disagreeing: they say nothing either way.
     *
     * @return array{compared: int, agreeing: int, unchecked: int, wrong_route: int, rivalled: int}
     */
    private function sampleDiagnostics(): array
    {
        $row = DB::selectOne(<<<'SQL'
            select
                count(case when checkable and candidate_route_key is not null then 1 end) as compared,
                count(case when checkable and candidate_route_key is not null and candidate_agrees then 1 end) as agreeing,
                count(case when checkable and candidate_route_key is null then 1 end) as unchecked,
                count(case when candidate_section_id is not null and aadt_section_id is null and not rivalled then 1 end) as wrong_route,
                count(case when rivalled then 1 end) as rivalled
            from (
                select
                    samples.*,
                    candidate_section_id is not null and candidate_route_num is not null and ref is not null as checkable
                from aadt_match_samples samples
            ) samples
        SQL);

        return [
            'compared' => (int) ($row->compared ?? 0),
            'agreeing' => (int) ($row->agreeing ?? 0),
            'unchecked' => (int) ($row->unchecked ?? 0),
            'wrong_route' => (int) ($row->wrong_route ?? 0),
            'rivalled' => (int) ($row->rivalled ?? 0),
        ];
    }

    /**
     * VTrans' route code written the way OpenStreetMap writes a route
     * number, or null for a code OpenStreetMap has no equivalent for.
     *
     * 'U007-S' is US 7 southbound and 'V010A' is VT 10A, so the direction
     * comes off but the letter suffix stays: VT 10 and VT 10A are different
     * roads.
     */
    private function routeKeySql(string $routeNum): string
    {
        return <<<SQL
            case substring({$routeNum}, 1, 1)
                when 'I' then 'I'
                when 'U' then 'US'
                when 'V' then 'VT'
            end || ltrim(regexp_replace(substring({$routeNum}, 2), '-[NSEW]\$', ''), '0')
            SQL;
    }

    /**
     * The bearing of a line at a point along it, taken over
     * {@see self::BEARING_WINDOW_M} either side to smooth out the jitter of
     * individual vertices.
     *
     * Null on a line too short or too degenerate to have a direction, which
     * {@see self::bearingsAgreeSql()} reads as no evidence either way.
     */
    private function bearingAtSql(string $line, string $position, string $lengthM): string
    {
        $window = (float) self::BEARING_WINDOW_M;
        $half = "least(0.5, {$window} / nullif({$lengthM}, 0))";

        return <<<SQL
            ST_Azimuth(
                ST_LineInterpolatePoint({$line}, greatest(0, ({$position}) - {$half})),
                ST_LineInterpolatePoint({$line}, least(1, ({$position}) + {$half}))
            )
            SQL;
    }

    /**
     * Whether two bearings run along the same line.
     *
     * Taken modulo 180, since a road drawn the other way round is still the
     * same road: `90 - abs(difference - 90)` is the acute angle between the
     * two lines whichever way either of them was digitised. An unknown
     * bearing is not evidence of disagreement, so it passes.
     */
    private function bearingsAgreeSql(string $first, string $second): string
    {
        $delta = (float) self::MAX_BEARING_DELTA_DEGREES;

        return <<<SQL
            (
                {$first} is null
                or {$second} is null
                or 90 - abs(mod(mod(degrees({$first} - {$second})::numeric, 180) + 180, 180) - 90) <= {$delta}
            )
            SQL;
    }

    private function dropScratchTables(): void
    {
        foreach (['aadt_match_samples', 'aadt_match_sections', 'aadt_match_ways'] as $table) {
            DB::statement("drop table if exists {$table}");
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function sqlArray(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }

    /**
     * The same, written into the statement rather than bound: these come
     * from the constants above, never from input.
     *
     * @param  list<string>  $values
     */
    private function sqlArrayLiteral(array $values): string
    {
        return "'".$this->sqlArray($values)."'::text[]";
    }
}
