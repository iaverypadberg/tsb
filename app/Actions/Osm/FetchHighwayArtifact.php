<?php

namespace App\Actions\Osm;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Resolves a published highway artifact to a file on local disk.
 *
 * CI publishes each build as a GitHub release asset, tagged by date, so the
 * artifact an environment loaded is always identifiable and an older one can
 * be loaded again by naming its tag.
 *
 * The repository is private, so both listing releases and downloading an
 * asset need a token with read access to its contents.
 */
class FetchHighwayArtifact
{
    /**
     * @param  string|null  $tag  A release tag, or null for the newest matching the configured prefix.
     * @param  (callable(string): void)|null  $announce  Called with what is about to be downloaded.
     * @return array{path: string, tag: string, bytes: int}
     */
    public function handle(?string $tag = null, ?callable $announce = null): array
    {
        $release = $tag === null ? $this->newestRelease() : $this->taggedRelease($tag);

        $asset = $this->findAsset($release);

        if ($announce !== null) {
            $announce("{$release['tag_name']} / {$asset['name']}");
        }

        $destination = rtrim((string) config('osm.artifact.path'), '/')
            .'/'.$release['tag_name'].'-'.$asset['name'];

        File::ensureDirectoryExists(dirname($destination));

        $this->download((int) $asset['id'], $destination);

        return [
            'path' => $destination,
            'tag' => (string) $release['tag_name'],
            'bytes' => (int) filesize($destination),
        ];
    }

    /**
     * The newest release whose tag carries the artifact prefix.
     *
     * `releases/latest` is deliberately not used: it would also match an
     * application release, and the artifact tags share the repository with
     * whatever else gets tagged there.
     *
     * @return array<string, mixed>
     */
    private function newestRelease(): array
    {
        $prefix = (string) config('osm.artifact.tag_prefix');

        // Releases come back newest first, so the first match is the one.
        $releases = $this->request()
            ->accept('application/vnd.github+json')
            ->get($this->repositoryUrl().'/releases', ['per_page' => 100])
            ->throw()
            ->json();

        foreach ($releases as $release) {
            if (str_starts_with((string) $release['tag_name'], $prefix)) {
                return $release;
            }
        }

        throw new RuntimeException(
            "No release tagged [{$prefix}*] in ".config('osm.artifact.repository')
            .'. Has the osm-refresh workflow run?',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function taggedRelease(string $tag): array
    {
        $response = $this->request()
            ->accept('application/vnd.github+json')
            ->get($this->repositoryUrl()."/releases/tags/{$tag}");

        if ($response->status() === 404) {
            throw new RuntimeException("No release tagged [{$tag}] in ".config('osm.artifact.repository').'.');
        }

        return $response->throw()->json();
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    private function findAsset(array $release): array
    {
        $name = (string) config('osm.artifact.name');

        foreach ($release['assets'] ?? [] as $asset) {
            if ($asset['name'] === $name) {
                return $asset;
            }
        }

        throw new RuntimeException(
            "Release [{$release['tag_name']}] has no asset named [{$name}].",
        );
    }

    /**
     * Streamed to disk: the artifact does not belong in memory.
     */
    private function download(int $assetId, string $destination): void
    {
        $response = $this->request()
            ->accept('application/octet-stream')
            ->timeout(900)
            ->sink($destination)
            ->get($this->repositoryUrl()."/releases/assets/{$assetId}");

        if ($response->failed()) {
            File::delete($destination);

            throw new RuntimeException("Downloading the artifact failed with status {$response->status()}.");
        }

        $this->assertGzip($destination);
    }

    /**
     * The asset endpoint answers with the asset's metadata as JSON unless it
     * is asked for octet-stream, and that JSON would otherwise be reported
     * much later as an artifact with an unreadable header. Checking the gzip
     * magic number here names the real problem instead.
     */
    private function assertGzip(string $destination): void
    {
        $handle = fopen($destination, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 2);

        if ($handle !== false) {
            fclose($handle);
        }

        if ($magic === "\x1f\x8b") {
            return;
        }

        $size = (int) filesize($destination);

        File::delete($destination);

        throw new RuntimeException(
            "GitHub returned {$size} bytes that are not a gzip file. The asset request was "
            .'answered with something other than the asset itself.',
        );
    }

    private function request(): PendingRequest
    {
        $token = (string) config('osm.artifact.token');

        if ($token === '') {
            throw new RuntimeException(
                'OSM_ARTIFACT_TOKEN is not set. It needs a token with read access to the repository contents.',
            );
        }

        // Accept is deliberately left out here and set per call. Laravel
        // merges headers recursively, so an Accept set on this base request
        // would combine with the download's rather than be replaced by it,
        // and GitHub would answer the asset request with its metadata JSON
        // instead of the file.
        return Http::withToken($token)
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
                // GitHub requires a User-Agent and rejects requests without one.
                'User-Agent' => 'bad-roads-osm-artifact',
            ])
            ->timeout(60);
    }

    private function repositoryUrl(): string
    {
        $repository = (string) config('osm.artifact.repository');

        if ($repository === '') {
            throw new RuntimeException('OSM_ARTIFACT_REPOSITORY is not set.');
        }

        return "https://api.github.com/repos/{$repository}";
    }
}
