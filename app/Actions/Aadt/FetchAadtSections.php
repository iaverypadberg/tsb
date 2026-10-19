<?php

namespace App\Actions\Aadt;

use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Asks the VTrans feature service for every published traffic count.
 *
 * Each layer is walked a page at a time and handed back as it arrives. The
 * service will answer for the whole state in one request at the service
 * level `/query`, but 13 MB of Esri JSON needs most of a gigabyte of PHP
 * heap once decoded, which the default 128 MB limit does not survive. Paging
 * keeps the peak flat however many sections are published.
 *
 * ArcGIS caps a response at `maxRecordCount` and says so with
 * `exceededTransferLimit` rather than failing, so completeness is not
 * assumed: the layer is counted first and the pages are checked against
 * that count. A silently short answer would understate how busy a road is,
 * which is the one mistake worth writing code against.
 */
class FetchAadtSections
{
    /**
     * Walk every configured layer, a page of sections at a time.
     *
     * @return Generator<int, array{layer: int, features: list<array<string, mixed>>}>
     */
    public function handle(): Generator
    {
        foreach (array_map('intval', (array) config('aadt.layers')) as $layerId) {
            yield from $this->pages($layerId);
        }
    }

    /**
     * @return Generator<int, array{layer: int, features: list<array<string, mixed>>}>
     */
    private function pages(int $layerId): Generator
    {
        $expected = $this->count($layerId);
        $pageSize = max(1, (int) config('aadt.page_size'));
        $offset = 0;
        $seen = 0;

        do {
            $features = $this->features($this->query($layerId, [
                'where' => '1=1',
                'outFields' => '*',
                'returnGeometry' => 'true',
                'outSR' => 4326,
                // Without an order the pages are free to overlap.
                'orderByFields' => 'OBJECTID',
                'resultOffset' => $offset,
                'resultRecordCount' => $pageSize,
            ]));

            $seen += count($features);
            $offset += $pageSize;

            if ($features !== []) {
                yield ['layer' => $layerId, 'features' => $features];
            }
        } while (count($features) === $pageSize && $seen < $expected);

        if ($seen !== $expected) {
            throw new RuntimeException(
                "Layer [{$layerId}] holds {$expected} sections but answered with {$seen}."
            );
        }
    }

    /**
     * How many sections the layer says it holds, so a short answer is caught.
     */
    private function count(int $layerId): int
    {
        $count = $this->query($layerId, [
            'where' => '1=1',
            'returnCountOnly' => 'true',
        ])->json('count');

        if (! is_numeric($count)) {
            throw new RuntimeException("Layer [{$layerId}] would not say how many sections it holds.");
        }

        return (int) $count;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function query(int $layerId, array $parameters): Response
    {
        $url = rtrim((string) config('aadt.service_url'), '/')."/{$layerId}/query";

        $response = Http::asForm()
            ->timeout((int) config('aadt.timeout'))
            ->post($url, [...$parameters, 'f' => 'json']);

        if ($response->failed()) {
            throw new RuntimeException("The traffic count service answered {$response->status()}.");
        }

        // ArcGIS reports its own failures inside a 200 response.
        if (is_array($error = $response->json('error'))) {
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'an unknown error';

            throw new RuntimeException("The traffic count service reported: {$message}.");
        }

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function features(Response $response): array
    {
        $features = $response->json('features');

        return is_array($features) ? array_values(array_filter($features, 'is_array')) : [];
    }
}
