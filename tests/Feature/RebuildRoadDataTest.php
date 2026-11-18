<?php

use App\Actions\Osm\ExportStagedHighways;
use App\Models\AadtSection;
use App\Models\Crash;
use App\Models\RoadSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Roughly 11 metres of latitude, inside the traffic match buffer: a
 * published section is drawn along a route's centreline, so it never sits
 * exactly on the way a rider is matched to.
 */
const BESIDE_THE_WAY = 0.0001;

/**
 * Stage one north-south way, the way a loaded artifact would.
 */
function stageWayForRebuild(int $wayId = 1, float $fromLatitude = 44.26, float $toLatitude = 44.27): void
{
    DB::table('osm_highways')->insert([
        'osm_way_id' => $wayId,
        'name' => 'Rebuilt Road',
        'highway' => 'secondary',
        'maxspeed' => '45 mph',
        'geom' => DB::raw(
            "ST_GeomFromText('LINESTRING(-72.58 {$fromLatitude}, -72.58 {$toLatitude})', 4326)",
        ),
    ]);
}

it('matches traffic and crashes onto freshly rebuilt segments', function () {
    stageWayForRebuild();

    AadtSection::factory()
        ->between(44.26, -72.58 + BESIDE_THE_WAY, 44.27, -72.58 + BESIDE_THE_WAY)
        ->create(['aadt' => 8_400]);

    Crash::factory()->at(44.265, -72.58)->create();

    $this->artisan('roads:rebuild', ['--skip-load' => true])->assertSuccessful();

    $segment = RoadSegment::where('osm_way_id', 1)->sole();

    // Both matches run against geometry that build-segments has just
    // replaced, so landing on the segment at all proves the ordering held.
    expect($segment->aadt)->toBe(8_400)
        ->and($segment->crash_count)->toBe(1);
});

it('stops at the first failing step and says which one', function () {
    // Nothing staged, so interpreting the staged ways fails and the matches
    // that follow it must not run.
    Crash::factory()->at(44.265, -72.58)->create();

    $this->artisan('roads:rebuild', ['--skip-load' => true])
        ->expectsOutputToContain('Stopped at: Interpreting staged ways into road segments')
        ->assertFailed();

    expect(RoadSegment::count())->toBe(0)
        ->and(Crash::sole()->road_segment_id)->toBeNull();
});

it('loads the artifact before rebuilding when one is given', function () {
    $path = sys_get_temp_dir().'/'.uniqid('osm-rebuild-', true).'.ndjson.gz';

    stageWayForRebuild(wayId: 7);

    app(ExportStagedHighways::class)->handle($path, 'vermont-latest.osm.pbf');

    DB::table('osm_highways')->delete();

    $this->artisan('roads:rebuild', ['artifact' => $path])->assertSuccessful();

    expect(DB::table('osm_highways')->count())->toBe(1)
        ->and(RoadSegment::where('osm_way_id', 7)->exists())->toBeTrue();
});

it('skips a match whose source data is absent rather than failing the rebuild', function () {
    stageWayForRebuild();

    // Crashes, but no published traffic counts: the traffic match would fail
    // outright if it ran, and taking the rebuild down with it would leave
    // segments replaced but never matched.
    Crash::factory()->at(44.265, -72.58)->create();

    $this->artisan('roads:rebuild', ['--skip-load' => true])
        ->expectsOutputToContain('aadt_sections holds nothing to match')
        ->assertSuccessful();

    $segment = RoadSegment::where('osm_way_id', 1)->sole();

    expect($segment->aadt)->toBeNull()
        ->and($segment->crash_count)->toBe(1);
});
