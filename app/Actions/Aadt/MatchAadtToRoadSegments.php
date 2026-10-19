<?php

namespace App\Actions\Aadt;

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
 * The route number is still read, as a check on the geometry rather than as
 * the thing doing the matching.
 *
 * So each way is sampled along its length and every sample is matched to the
 * nearest section whose bearing agrees, which is the same approach
 * `MatchRouteToRoadSegments` takes to put a route on the road network. The
 * bearing test is what stops a frontage road, a rail trail or the opposite
 * carriageway inheriting a highway's traffic.
 *
 * Everything happens inside Postgres against projected scratch tables. The
 * counts are published in Vermont State Plane, and working in it means
 * distances are already metres; transforming geometry inside the join
 * instead would make the spatial index useless and the query unusable.
 */
class MatchAadtToRoadSegments
{
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
     * Match every road against one year of counts.
     *
     * @return array{year: int, roads_measured: int, matches: int, compared: int, agreeing: int, unchecked: int}
     */
    public function handle(?int $year = null): array
    {
        $year ??= AadtSection::latestYear();

        if ($year === null) {
            return ['year' => 0, 'roads_measured' => 0, 'matches' => 0, 'compared' => 0, 'agreeing' => 0, 'unchecked' => 0];
        }

        $this->projectSections($year);
        $this->projectRoadSegments();
        $this->takeSamples();

        $result = DB::transaction(function () use ($year): array {
            $this->clearPreviousMatches($year);

            return [
                'year' => $year,
                'matches' => $this->recordMatches(),
                'roads_measured' => $this->recordCounts($year),
            ];
        });

        $result = [...$result, ...$this->refAgreement()];

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

        DB::statement(<<<'SQL'
            create unlogged table aadt_match_sections as
            select
                sections.id as aadt_section_id,
                sections.aadt,
                sections.route_num,
                piece.geom
            from aadt_sections sections
            cross join lateral ST_Dump(ST_Transform(sections.geom, ?::int)) as piece
            where sections.year = ?::int
              and (sections.route_type is null or sections.route_type <> all(?::text[]))
        SQL, [self::PROJECTED_SRID, $year, $this->excludedRouteTypes()]);

        DB::statement('create index on aadt_match_sections using gist (geom)');
        DB::statement('analyze aadt_match_sections');
    }

    private function projectRoadSegments(): void
    {
        DB::statement('drop table if exists aadt_match_ways');

        DB::statement(<<<'SQL'
            create unlogged table aadt_match_ways as
            select
                segments.id as road_segment_id,
                segments.ref,
                projected.geom,
                ST_Length(projected.geom) as length_m
            from road_segments segments
            cross join lateral (select ST_Transform(segments.geom, ?::int) as geom) projected
            where ST_Length(projected.geom) > 0
              -- Most of the network is residential lanes nobody counts, and
              -- sampling a road no section comes near only to match nothing
              -- is the bulk of the work avoided here.
              and exists (
                select 1
                from aadt_match_sections sections
                where ST_DWithin(sections.geom, projected.geom, ?::float)
              )
        SQL, [self::PROJECTED_SRID, self::MATCH_BUFFER_M]);

        DB::statement('create index on aadt_match_ways using gist (geom)');
        DB::statement('analyze aadt_match_ways');
    }

    /**
     * Sample every road and match each sample to a section.
     *
     * Samples are evenly spaced, so each one stands for the same length of
     * road: averaging over them is already a length weighted average, and a
     * short clip of a busy junction cannot relabel a quiet lane.
     */
    private function takeSamples(): void
    {
        DB::statement('drop table if exists aadt_match_samples');

        DB::statement(<<<'SQL'
            create unlogged table aadt_match_samples as
            with steps as (
                select
                    ways.road_segment_id,
                    ways.ref,
                    ways.geom,
                    ways.length_m,
                    greatest(1, (ways.length_m / ?::float)::int) as step_count
                from aadt_match_ways ways
            )
            select
                steps.road_segment_id,
                steps.ref,
                match.aadt_section_id,
                match.aadt,
                match.route_num
            from steps
            cross join generate_series(0, steps.step_count) as step(i)
            cross join lateral (
                select
                    ST_LineInterpolatePoint(steps.geom, step.i::float / steps.step_count) as point,
                    least(0.5, ?::float / steps.length_m) as window
            ) sample
            cross join lateral (
                select ST_Azimuth(
                    ST_LineInterpolatePoint(steps.geom, greatest(0, step.i::float / steps.step_count - sample.window)),
                    ST_LineInterpolatePoint(steps.geom, least(1, step.i::float / steps.step_count + sample.window))
                ) as bearing
            ) road
            left join lateral (
                select
                    sections.aadt_section_id,
                    sections.aadt,
                    sections.route_num
                from aadt_match_sections sections
                cross join lateral (
                    select
                        least(0.5, ?::float / nullif(ST_Length(sections.geom), 0)) as window,
                        ST_LineLocatePoint(sections.geom, sample.point) as position
                ) loc
                cross join lateral (
                    select ST_Azimuth(
                        ST_LineInterpolatePoint(sections.geom, greatest(0, loc.position - loc.window)),
                        ST_LineInterpolatePoint(sections.geom, least(1, loc.position + loc.window))
                    ) as bearing
                ) section
                cross join lateral (
                    select abs(degrees(road.bearing) - degrees(section.bearing)) as raw
                ) difference
                cross join lateral (
                    select least(difference.raw, 360 - difference.raw) as absolute
                ) shortest
                where ST_DWithin(sections.geom, sample.point, ?::float)
                  and (
                    road.bearing is null
                    or section.bearing is null
                    or least(shortest.absolute, 180 - shortest.absolute) <= ?::float
                  )
                order by sections.geom <-> sample.point
                limit 1
            ) match on true
        SQL, [
            self::SAMPLE_INTERVAL_M,
            self::BEARING_WINDOW_M,
            self::BEARING_WINDOW_M,
            self::MATCH_BUFFER_M,
            self::MAX_BEARING_DELTA_DEGREES,
        ]);

        DB::statement('create index on aadt_match_samples (road_segment_id)');
        DB::statement('analyze aadt_match_samples');
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
     * Whether the road's own route number agrees with the section matched to
     * it, over every sample where the two can be compared at all.
     *
     * The only independent check there is on the geometry: nothing else says
     * whether a road was given the right count.
     *
     * Only numbered routes are checkable. A town highway carries a code of
     * VTrans' own (`S3004`, `FAU3030`) that OpenStreetMap has no equivalent
     * for, so those samples are counted as unchecked rather than as
     * disagreeing: they say nothing either way.
     *
     * @return array{compared: int, agreeing: int, unchecked: int}
     */
    private function refAgreement(): array
    {
        $row = DB::selectOne(<<<'SQL'
            with decoded as (
                select
                    upper(ref) as ref,
                    -- 'U007-S' is US 7 southbound and 'V010A' is VT 10A, so
                    -- the direction comes off but the letter suffix stays:
                    -- VT 10 and VT 10A are different roads.
                    case substring(route_num, 1, 1)
                        when 'I' then 'I'
                        when 'U' then 'US'
                        when 'V' then 'VT'
                    end || ltrim(regexp_replace(substring(route_num, 2), '-[NSEW]$', ''), '0') as route_key
                from aadt_match_samples
                where route_num is not null and ref is not null
            ),
            checked as (
                select
                    route_key,
                    exists (
                        -- A road can carry two numbers at once, which
                        -- OpenStreetMap writes into one tag separated by a
                        -- semicolon. Punctuation differs between the two
                        -- sources ('I-91' against 'I 91'), so both sides are
                        -- stripped to letters and digits before comparing.
                        select 1
                        from unnest(string_to_array(ref, ';')) as alternative
                        where regexp_replace(alternative, '[^A-Z0-9]', '', 'g') = route_key
                    ) as agrees
                from decoded
            )
            select
                count(route_key) as compared,
                count(case when agrees then 1 end) as agreeing,
                count(*) - count(route_key) as unchecked
            from checked
        SQL);

        return [
            'compared' => (int) ($row->compared ?? 0),
            'agreeing' => (int) ($row->agreeing ?? 0),
            'unchecked' => (int) ($row->unchecked ?? 0),
        ];
    }

    private function dropScratchTables(): void
    {
        foreach (['aadt_match_samples', 'aadt_match_sections', 'aadt_match_ways'] as $table) {
            DB::statement("drop table if exists {$table}");
        }
    }

    private function excludedRouteTypes(): string
    {
        return '{'.implode(',', self::EXCLUDED_ROUTE_TYPES).'}';
    }
}
