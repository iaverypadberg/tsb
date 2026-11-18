<?php

use App\Actions\Osm\FetchHighwayArtifact;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('osm.artifact.repository', 'example/roads');
    config()->set('osm.artifact.token', 'test-token');
    config()->set('osm.artifact.tag_prefix', 'osm-');
    config()->set('osm.artifact.name', 'osm_highways.ndjson.gz');
    config()->set('osm.artifact.path', sys_get_temp_dir().'/'.uniqid('osm-fetch-', true));

    Http::preventStrayRequests();
});

/**
 * One entry as the releases endpoint returns it.
 *
 * @return array<string, mixed>
 */
function githubRelease(string $tag, int $assetId = 1, string $assetName = 'osm_highways.ndjson.gz'): array
{
    return [
        'tag_name' => $tag,
        'assets' => [['id' => $assetId, 'name' => $assetName]],
    ];
}

it('downloads the newest release carrying the artifact tag prefix', function () {
    Http::fake([
        'api.github.com/repos/example/roads/releases?*' => Http::response([
            // Newest first, as the API returns them. The application release
            // sits in front of the artifact releases and must be skipped.
            githubRelease('v2.1.0', assetId: 99),
            githubRelease('osm-2026-11-18', assetId: 42),
            githubRelease('osm-2026-10-02', assetId: 41),
        ]),
        'api.github.com/repos/example/roads/releases/assets/42' => Http::response('artifact-bytes'),
    ]);

    $fetched = app(FetchHighwayArtifact::class)->handle();

    expect($fetched['tag'])->toBe('osm-2026-11-18')
        ->and(file_get_contents($fetched['path']))->toBe('artifact-bytes');
});

it('downloads the release named instead of the newest', function () {
    Http::fake([
        'api.github.com/repos/example/roads/releases/tags/osm-2026-10-02' => Http::response(
            githubRelease('osm-2026-10-02', assetId: 41),
        ),
        'api.github.com/repos/example/roads/releases/assets/41' => Http::response('older-bytes'),
    ]);

    $fetched = app(FetchHighwayArtifact::class)->handle('osm-2026-10-02');

    expect($fetched['tag'])->toBe('osm-2026-10-02')
        ->and(file_get_contents($fetched['path']))->toBe('older-bytes');
});

it('fails when no release carries the artifact prefix', function () {
    Http::fake([
        'api.github.com/repos/example/roads/releases?*' => Http::response([githubRelease('v2.1.0')]),
    ]);

    expect(fn () => app(FetchHighwayArtifact::class)->handle())
        ->toThrow(RuntimeException::class, 'No release tagged [osm-*]');
});

it('fails when the named release does not exist', function () {
    Http::fake([
        'api.github.com/repos/example/roads/releases/tags/osm-1999-01-01' => Http::response(status: 404),
    ]);

    expect(fn () => app(FetchHighwayArtifact::class)->handle('osm-1999-01-01'))
        ->toThrow(RuntimeException::class, 'No release tagged [osm-1999-01-01]');
});

it('fails when a release carries no artifact asset', function () {
    Http::fake([
        'api.github.com/repos/example/roads/releases?*' => Http::response([
            githubRelease('osm-2026-11-18', assetName: 'notes.txt'),
        ]),
    ]);

    expect(fn () => app(FetchHighwayArtifact::class)->handle())
        ->toThrow(RuntimeException::class, 'no asset named [osm_highways.ndjson.gz]');
});

it('fails before calling GitHub when no token is configured', function () {
    config()->set('osm.artifact.token', null);

    // preventStrayRequests with no fakes registered: reaching GitHub at all
    // would fail the test rather than pass it.
    expect(fn () => app(FetchHighwayArtifact::class)->handle())
        ->toThrow(RuntimeException::class, 'OSM_ARTIFACT_TOKEN is not set');
});

it('sends the token and a user agent GitHub will accept', function () {
    Http::fake([
        'api.github.com/repos/example/roads/releases?*' => Http::response([githubRelease('osm-2026-11-18', assetId: 42)]),
        'api.github.com/repos/example/roads/releases/assets/42' => Http::response('artifact-bytes'),
    ]);

    app(FetchHighwayArtifact::class)->handle();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->hasHeader('User-Agent', 'bad-roads-osm-artifact'));
});
