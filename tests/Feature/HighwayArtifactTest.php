<?php

use App\Actions\Osm\ExportStagedHighways;
use App\Actions\Osm\HighwayArtifact;
use App\Actions\Osm\LoadStagedHighways;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Stage one way the way osm2pgsql would, with every tag left null unless
 * named: a null tag is the common case, and the one the loader is most
 * likely to mangle.
 *
 * @param  array<string, string|null>  $tags
 */
function stageWayForExport(int $wayId, array $tags = [], string $geometry = 'LINESTRING(-72.58 44.26, -72.57 44.27)'): void
{
    DB::table('osm_highways')->insert([
        ...array_fill_keys(array_diff(HighwayArtifact::COLUMNS, ['osm_way_id', 'highway']), null),
        ...$tags,
        'osm_way_id' => $wayId,
        'highway' => $tags['highway'] ?? 'residential',
        'geom' => DB::raw("ST_GeomFromText('{$geometry}', 4326)"),
    ]);
}

/**
 * Everything about the staged ways that an artifact is supposed to carry,
 * geometry included, in a form two tables can be compared on.
 *
 * @return array<int, array<string, mixed>>
 */
function stagedWaysSnapshot(): array
{
    return DB::table('osm_highways')
        ->select([...HighwayArtifact::COLUMNS, DB::raw('ST_AsEWKT(geom) as geom')])
        ->orderBy('osm_way_id')
        ->get()
        ->map(fn ($way): array => (array) $way)
        ->all();
}

function artifactPath(string $name = 'artifact.ndjson.gz'): string
{
    return sys_get_temp_dir().'/'.uniqid('osm-test-', true).'-'.$name;
}

/**
 * Write an artifact by hand, so a malformed one can be described exactly.
 *
 * @param  array<string, mixed>  $header
 * @param  list<array<string, mixed>>  $rows
 */
function writeArtifact(string $path, array $header, array $rows = []): string
{
    $handle = gzopen($path, 'wb');

    gzwrite($handle, json_encode($header)."\n");

    foreach ($rows as $row) {
        gzwrite($handle, json_encode($row)."\n");
    }

    gzclose($handle);

    return $path;
}

/**
 * Hex WKB as the export writes it, encoded by PostGIS rather than by hand:
 * a hand-rolled byte string is its own source of test failures.
 */
function validGeometryHex(string $geometry = 'LINESTRING(-72.58 44.26, -72.57 44.27)'): string
{
    return (string) DB::scalar(
        "select encode(ST_AsBinary(ST_GeomFromText(?, 4326)), 'hex')",
        [$geometry],
    );
}

it('round-trips tags, null tags and geometry through an artifact', function () {
    stageWayForExport(1, ['highway' => 'trunk', 'name' => 'Big Road', 'ref' => 'US 7', 'maxspeed' => '55 mph']);
    stageWayForExport(2, ['highway' => 'service', 'service_type' => 'driveway']);
    stageWayForExport(3, ['highway' => 'cycleway'], 'LINESTRING(-73.1 44.47, -73.09 44.48, -73.08 44.49)');

    $before = stagedWaysSnapshot();
    $path = artifactPath();

    $exported = app(ExportStagedHighways::class)->handle($path, 'vermont-latest.osm.pbf');

    DB::table('osm_highways')->delete();

    $loaded = app(LoadStagedHighways::class)->handle($path);

    expect($exported['rows'])->toBe(3)
        ->and($loaded['rows'])->toBe(3)
        ->and($loaded['header']['source'])->toBe('vermont-latest.osm.pbf')
        ->and(stagedWaysSnapshot())->toEqual($before);

    // toEqual would pass for an empty string where a null belongs, so the
    // null tags are named outright.
    expect(DB::table('osm_highways')->where('osm_way_id', 2)->value('name'))->toBeNull()
        ->and(DB::table('osm_highways')->where('osm_way_id', 2)->value('maxspeed'))->toBeNull();
});

it('keeps the previously staged ways when an artifact is truncated', function () {
    stageWayForExport(1, ['name' => 'Already Staged']);

    $before = stagedWaysSnapshot();

    // A header promising two ways, with one in the file: what a download cut
    // short, or an export killed part-way, leaves behind.
    $path = writeArtifact(artifactPath(), [
        'format' => HighwayArtifact::FORMAT,
        'generated_at' => now()->toIso8601String(),
        'source' => 'vermont-latest.osm.pbf',
        'rows' => 2,
        'columns' => [...HighwayArtifact::COLUMNS, 'geom'],
    ], [[
        'osm_way_id' => 9,
        'highway' => 'residential',
        'geom' => validGeometryHex(),
    ]]);

    expect(fn () => app(LoadStagedHighways::class)->handle($path))
        ->toThrow(RuntimeException::class, 'promised 2 ways but 1 were read');

    expect(stagedWaysSnapshot())->toEqual($before);
});

it('refuses an artifact written in a format it does not read', function () {
    $path = writeArtifact(artifactPath(), [
        'format' => HighwayArtifact::FORMAT + 1,
        'generated_at' => now()->toIso8601String(),
        'source' => 'vermont-latest.osm.pbf',
        'rows' => 0,
        'columns' => [...HighwayArtifact::COLUMNS, 'geom'],
    ]);

    expect(fn () => app(LoadStagedHighways::class)->handle($path))
        ->toThrow(RuntimeException::class, 'format '.(HighwayArtifact::FORMAT + 1));
});

it('refuses an artifact that is missing a tag column', function () {
    $path = writeArtifact(artifactPath(), [
        'format' => HighwayArtifact::FORMAT,
        'generated_at' => now()->toIso8601String(),
        'source' => 'vermont-latest.osm.pbf',
        'rows' => 0,
        'columns' => array_values(array_diff(HighwayArtifact::COLUMNS, ['shoulder'])),
    ]);

    expect(fn () => app(LoadStagedHighways::class)->handle($path))
        ->toThrow(RuntimeException::class, 'missing the column(s): shoulder');
});

it('fails the export when nothing is staged', function () {
    $this->artisan('osm:export', ['--path' => artifactPath()])
        ->expectsOutputToContain('osm_highways is empty')
        ->assertFailed();
});

it('writes nothing to the staging table on a dry run', function () {
    stageWayForExport(1);
    stageWayForExport(2);

    $path = artifactPath();

    app(ExportStagedHighways::class)->handle($path, 'vermont-latest.osm.pbf');

    DB::table('osm_highways')->delete();

    $this->artisan('osm:load', ['artifact' => $path, '--dry-run' => true])
        ->expectsOutputToContain('2 ways read and checked')
        ->assertSuccessful();

    expect(DB::table('osm_highways')->count())->toBe(0);
});

it('fails the load when there is no artifact at the given path', function () {
    $this->artisan('osm:load', ['artifact' => '/nonexistent/artifact.ndjson.gz'])
        ->expectsOutputToContain('No artifact at')
        ->assertFailed();
});
