<?php

use App\Models\RoadSegment;
use App\Models\SegmentReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Stage one OpenStreetMap way, the way osm2pgsql would.
 *
 * @param  array<string, string|null>  $tags
 */
function stageHighway(int $wayId, array $tags = []): void
{
    DB::table('osm_highways')->insert([
        'osm_way_id' => $wayId,
        'name' => $tags['name'] ?? 'Test Road',
        'ref' => $tags['ref'] ?? null,
        'highway' => $tags['highway'] ?? 'residential',
        'maxspeed' => $tags['maxspeed'] ?? null,
        'lanes' => $tags['lanes'] ?? null,
        'shoulder' => $tags['shoulder'] ?? null,
        'cycleway' => $tags['cycleway'] ?? null,
        'surface' => $tags['surface'] ?? null,
        'service_type' => $tags['service_type'] ?? null,
        'geom' => DB::raw("ST_GeomFromText('LINESTRING(-72.58 44.26, -72.57 44.27)', 4326)"),
    ]);
}

it('turns staged highways into scored road segments', function () {
    stageHighway(1, ['highway' => 'trunk', 'maxspeed' => '55 mph', 'name' => 'Big Road', 'ref' => 'US 7']);
    stageHighway(2, ['highway' => 'residential', 'maxspeed' => '25 mph', 'name' => 'Quiet Street']);

    $this->artisan('osm:build-segments')->assertSuccessful();

    expect(RoadSegment::count())->toBe(2);

    $trunk = RoadSegment::where('osm_way_id', 1)->sole();
    $residential = RoadSegment::where('osm_way_id', 2)->sole();

    expect($trunk->ref)->toBe('US 7')
        ->and($trunk->speed_limit_kmh)->toBe(89)
        ->and($trunk->danger_score)->toBeGreaterThan($residential->danger_score);
});

it('copies the geometry into the segment', function () {
    stageHighway(1);

    $this->artisan('osm:build-segments')->assertSuccessful();

    expect(RoadSegment::sole()->geom->getPoints())->toHaveCount(2);
});

it('leaves out driveways and parking aisles', function () {
    stageHighway(1, ['highway' => 'service', 'service_type' => 'driveway']);
    stageHighway(2, ['highway' => 'service', 'service_type' => 'parking_aisle']);
    stageHighway(3, ['highway' => 'service', 'service_type' => 'alley']);
    stageHighway(4, ['highway' => 'residential']);

    $this->artisan('osm:build-segments')->assertSuccessful();

    expect(RoadSegment::pluck('osm_way_id')->sort()->values()->all())->toBe([3, 4]);
});

it('keeps driveways when asked to', function () {
    stageHighway(1, ['highway' => 'service', 'service_type' => 'driveway']);

    $this->artisan('osm:build-segments', ['--include-service' => true])->assertSuccessful();

    expect(RoadSegment::count())->toBe(1);
});

it('carries the surface onto the segment', function () {
    stageHighway(1, ['highway' => 'tertiary', 'surface' => 'gravel']);

    $this->artisan('osm:build-segments')->assertSuccessful();

    expect(RoadSegment::sole()->surface)->toBe('gravel');
});

it('scores an unpaved back road below the same road paved', function () {
    stageHighway(1, ['highway' => 'tertiary', 'surface' => 'asphalt']);
    stageHighway(2, ['highway' => 'tertiary', 'surface' => 'gravel']);

    $this->artisan('osm:build-segments')->assertSuccessful();

    $paved = RoadSegment::where('osm_way_id', 1)->sole();
    $gravel = RoadSegment::where('osm_way_id', 2)->sole();

    expect($gravel->danger_score)->toBeLessThan($paved->danger_score);
});

it('scores a numbered route above the same road unnumbered', function () {
    stageHighway(1, ['highway' => 'secondary']);
    stageHighway(2, ['highway' => 'secondary', 'ref' => 'VT 12']);

    $this->artisan('osm:build-segments')->assertSuccessful();

    expect(RoadSegment::where('osm_way_id', 2)->sole()->danger_score)
        ->toBeGreaterThan(RoadSegment::where('osm_way_id', 1)->sole()->danger_score);
});

it('fails when nothing has been staged', function () {
    $this->artisan('osm:build-segments')->assertFailed();
});

it('updates segments in place so reports are not cascaded away', function () {
    stageHighway(1, ['highway' => 'residential', 'name' => 'Old Name']);
    $this->artisan('osm:build-segments')->assertSuccessful();

    $segment = RoadSegment::sole();
    SegmentReport::factory()->for($segment, 'roadSegment')->create(['reason' => 'Blind bend']);

    // The road gets renamed upstream and the import is run again.
    DB::table('osm_highways')->where('osm_way_id', 1)->update(['name' => 'New Name']);
    $this->artisan('osm:build-segments')->assertSuccessful();

    expect(RoadSegment::count())->toBe(1)
        ->and(RoadSegment::sole()->id)->toBe($segment->id)
        ->and(RoadSegment::sole()->name)->toBe('New Name')
        ->and(SegmentReport::count())->toBe(1);
});

it('raises the danger score by the severity of its reports', function () {
    stageHighway(1, ['highway' => 'residential', 'maxspeed' => '25 mph']);
    $this->artisan('osm:build-segments')->assertSuccessful();

    $baseline = RoadSegment::sole()->danger_score;

    SegmentReport::factory()->for(RoadSegment::sole(), 'roadSegment')->severity(4)->create();
    $this->artisan('osm:build-segments')->assertSuccessful();

    // Five points of score per point of severity.
    expect(RoadSegment::sole()->danger_score)->toBe($baseline + 20);
});

it('caps how far reports can push a score', function () {
    stageHighway(1, ['highway' => 'residential', 'maxspeed' => '25 mph']);
    $this->artisan('osm:build-segments')->assertSuccessful();

    $baseline = RoadSegment::sole()->danger_score;

    SegmentReport::factory()->count(5)->for(RoadSegment::sole(), 'roadSegment')->severity(5)->create();
    $this->artisan('osm:build-segments')->assertSuccessful();

    expect(RoadSegment::sole()->danger_score)->toBe($baseline + 30);
});

it('releases the score again when a report is withdrawn', function () {
    stageHighway(1, ['highway' => 'residential', 'maxspeed' => '25 mph']);
    $this->artisan('osm:build-segments')->assertSuccessful();

    $baseline = RoadSegment::sole()->danger_score;

    $report = SegmentReport::factory()->for(RoadSegment::sole(), 'roadSegment')->severity(3)->create();
    $this->artisan('osm:build-segments')->assertSuccessful();
    expect(RoadSegment::sole()->danger_score)->toBeGreaterThan($baseline);

    $report->delete();
    $this->artisan('osm:build-segments')->assertSuccessful();

    expect(RoadSegment::sole()->danger_score)->toBe($baseline);
});

it('never exceeds the maximum danger score', function () {
    stageHighway(1, ['highway' => 'motorway']);
    $this->artisan('osm:build-segments')->assertSuccessful();

    SegmentReport::factory()->count(3)->for(RoadSegment::sole(), 'roadSegment')->severity(5)->create();
    $this->artisan('osm:build-segments')->assertSuccessful();

    expect(RoadSegment::sole()->danger_score)->toBe(RoadSegment::MAX_DANGER);
});
