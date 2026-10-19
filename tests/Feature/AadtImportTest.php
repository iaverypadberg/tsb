<?php

use App\Models\AadtSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('aadt.service_url', 'https://aadt.test/arcgis/rest/services/Layers/AADT/FeatureServer');
});

/**
 * One section as the VTrans service publishes it: Esri JSON, attributes in
 * their original casing, geometry as a list of paths.
 *
 * Passing null for the paths gives a section with no geometry at all, which
 * the service does publish for a handful of badly located counts.
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<list<array{float, float}>>|null  $paths
 * @return array<string, mixed>
 */
function aadtFeature(array $attributes = [], ?array $paths = [[[-72.58, 44.26], [-72.57, 44.27]]]): array
{
    $feature = [
        'attributes' => [
            'OBJECTID' => fake()->unique()->numberBetween(1, 99_999),
            'AADT' => 1_500,
            'Year' => '2025',
            'ETE_ID' => 'S3004',
            'ETE_BeginMM' => 0,
            'ETE_EndMM' => 0.34,
            'BeginMM' => 0,
            'EndMM' => 0.34,
            'RouteName' => 'CHURCH ST',
            'RouteNum' => 'S3004',
            'RouteType' => 'FAU',
            'FunctionalClass' => '5',
            'StandardRouteCode' => 'S30041119',
            'TownName' => 'RUTLAND CITY',
            'ATRStation' => 'R051',
            'ATRStatus' => null,
            'LOC_ERROR' => 'NO ERROR',
            'IsDivided' => 'N',
            'zzDateOfExtract' => 1_785_308_250_000,
            ...$attributes,
        ],
    ];

    if ($paths !== null) {
        $feature['geometry'] = ['paths' => $paths];
    }

    return $feature;
}

/**
 * Stand in for the feature service, one endpoint per layer.
 *
 * The importer counts a layer before reading it and then pages through it,
 * so the fake answers both: `returnCountOnly` with the total, and a query
 * with the slice `resultOffset` and `resultRecordCount` ask for. The
 * features are taken by reference so a test can revise what the service
 * publishes between two runs.
 *
 * @param  array<int, list<array<string, mixed>>>  $features
 */
function fakeAadtService(array &$features): void
{
    foreach ((array) config('aadt.layers') as $layerId) {
        $features[$layerId] ??= [];
    }

    Http::fake(function (Request $request) use (&$features) {
        preg_match('#/FeatureServer/(\d+)/query#', $request->url(), $matches);

        $published = $features[(int) ($matches[1] ?? -1)] ?? [];

        if (($request['returnCountOnly'] ?? null) === 'true') {
            return Http::response(['count' => count($published)]);
        }

        return Http::response(['features' => array_slice(
            $published,
            (int) ($request['resultOffset'] ?? 0),
            (int) ($request['resultRecordCount'] ?? count($published)),
        )]);
    });
}

it('imports both layers and builds the geometry in postgis', function () {
    $published = [
        AadtSection::LAYER_LIMITED => [
            aadtFeature(['ETE_ID' => 'I089', 'AADT' => 28_051, 'RouteType' => 'I', 'IsDivided' => 'Y']),
        ],
        AadtSection::LAYER_OTHER => [
            aadtFeature(['ETE_ID' => 'S3004', 'AADT' => 1_534]),
            aadtFeature(['ETE_ID' => 'V015', 'AADT' => 8_385, 'ETE_EndMM' => 1.837], paths: [
                [[-72.58, 44.26], [-72.57, 44.27]],
                [[-72.56, 44.28], [-72.55, 44.29]],
            ]),
        ],
    ];

    fakeAadtService($published);

    $this->artisan('aadt:import')->assertSuccessful();

    expect(AadtSection::count())->toBe(3);

    $interstate = AadtSection::where('ete_id', 'I089')->sole();

    expect($interstate->aadt)->toBe(28_051)
        ->and($interstate->layer_id)->toBe(AadtSection::LAYER_LIMITED)
        ->and($interstate->year)->toBe(2025)
        ->and($interstate->is_divided)->toBeTrue()
        ->and($interstate->town_name)->toBe('RUTLAND CITY')
        ->and($interstate->extracted_at?->year)->toBe(2026);

    // Every section is stored as a multi line string, because a few of them
    // genuinely come back in more than one piece.
    $shapes = DB::select('select ete_id, ST_GeometryType(geom) as kind, ST_NumGeometries(geom) as pieces from aadt_sections');

    expect(collect($shapes)->pluck('kind')->unique()->all())->toBe(['ST_MultiLineString'])
        ->and(collect($shapes)->firstWhere('ete_id', 'V015')->pieces)->toBe(2)
        ->and(collect($shapes)->firstWhere('ete_id', 'S3004')->pieces)->toBe(1);
});

it('drops sections the service published without geometry or a count', function () {
    $published = [
        AadtSection::LAYER_LIMITED => [
            aadtFeature(['ETE_ID' => 'I089']),
            aadtFeature(['ETE_ID' => 'I091', 'LOC_ERROR' => 'INVALID LOCATION MEASURE'], paths: null),
        ],
        AadtSection::LAYER_OTHER => [
            aadtFeature(['ETE_ID' => 'S3004', 'AADT' => null]),
        ],
    ];

    fakeAadtService($published);

    $this->artisan('aadt:import')
        ->expectsOutputToContain('1 unusable')
        ->assertSuccessful();

    expect(AadtSection::pluck('ete_id')->all())->toBe(['I089']);
});

it('refreshes a year in place instead of duplicating it', function () {
    $published = [
        AadtSection::LAYER_OTHER => [aadtFeature(['ETE_ID' => 'S3004', 'AADT' => 1_534])],
    ];

    fakeAadtService($published);

    $this->artisan('aadt:import')->assertSuccessful();

    // Next spring, same section, revised count.
    $published[AadtSection::LAYER_OTHER] = [aadtFeature(['ETE_ID' => 'S3004', 'AADT' => 1_720])];

    $this->artisan('aadt:import')->assertSuccessful();

    expect(AadtSection::count())->toBe(1)
        ->and(AadtSection::sole()->aadt)->toBe(1_720);
});

it('keeps the years the service has already overwritten', function () {
    AadtSection::factory()->year(2024)->create(['ete_id' => 'S3004', 'ete_begin_mm' => 0, 'ete_end_mm' => 0.34]);

    $published = [
        AadtSection::LAYER_OTHER => [aadtFeature(['ETE_ID' => 'S3004', 'Year' => '2025'])],
    ];

    fakeAadtService($published);

    $this->artisan('aadt:import')->assertSuccessful();

    expect(AadtSection::where('ete_id', 'S3004')->pluck('year')->sort()->values()->all())->toBe([2024, 2025]);
});

it('only removes sections the service has stopped publishing when asked to prune', function () {
    $stale = AadtSection::factory()->create([
        'year' => 2025,
        'ete_id' => 'S9999',
        'ete_begin_mm' => 0,
        'ete_end_mm' => 2,
    ]);

    $published = [
        AadtSection::LAYER_OTHER => [aadtFeature(['ETE_ID' => 'S3004', 'Year' => '2025'])],
    ];

    fakeAadtService($published);

    $this->artisan('aadt:import')->assertSuccessful();

    expect(AadtSection::whereKey($stale->id)->exists())->toBeTrue();

    $this->artisan('aadt:import --prune')->assertSuccessful();

    expect(AadtSection::whereKey($stale->id)->exists())->toBeFalse()
        ->and(AadtSection::where('ete_id', 'S3004')->exists())->toBeTrue();
});

it('pages through a layer rather than taking the first response as the whole of it', function () {
    config()->set('aadt.page_size', 2);

    $published = [
        AadtSection::LAYER_OTHER => [
            aadtFeature(['ETE_ID' => 'S0001']),
            aadtFeature(['ETE_ID' => 'S0002']),
            aadtFeature(['ETE_ID' => 'S0003']),
            aadtFeature(['ETE_ID' => 'S0004']),
            aadtFeature(['ETE_ID' => 'S0005']),
        ],
    ];

    fakeAadtService($published);

    $this->artisan('aadt:import')->assertSuccessful();

    expect(AadtSection::pluck('ete_id')->sort()->values()->all())
        ->toBe(['S0001', 'S0002', 'S0003', 'S0004', 'S0005']);
});

it('fails rather than scoring roads on a short answer', function () {
    // The service says it holds three sections and then hands over one, the
    // shape a response truncated at the record cap would take.
    Http::fake(fn (Request $request) => ($request['returnCountOnly'] ?? null) === 'true'
        ? Http::response(['count' => 3])
        : Http::response(['features' => [aadtFeature(['ETE_ID' => 'S0001'])]]));

    $this->artisan('aadt:import')
        ->expectsOutputToContain('holds 3 sections but answered with 1')
        ->assertFailed();
});

it('fails when the service reports an error inside a successful response', function () {
    Http::fake(['*' => Http::response([
        'error' => ['code' => 400, 'message' => 'Unable to complete operation.'],
    ])]);

    $this->artisan('aadt:import')
        ->expectsOutputToContain('Unable to complete operation.')
        ->assertFailed();

    expect(AadtSection::count())->toBe(0);
});
