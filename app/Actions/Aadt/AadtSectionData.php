<?php

namespace App\Actions\Aadt;

use Illuminate\Support\Carbon;

/**
 * One traffic count section as the VTrans service returned it.
 *
 * Esri JSON is read rather than GeoJSON because the service answers
 * `f=geojson` on this layer with an error, while `f=json` honours
 * `outSR=4326` and hands back `{"paths": [[[lon, lat], ...]]}`. Those paths
 * are already GeoJSON MultiLineString coordinates, so the geometry travels
 * on to Postgres as GeoJSON without being rebuilt.
 */
readonly class AadtSectionData
{
    /**
     * @param  list<list<array{float, float}>>  $paths  Longitude first, as GeoJSON orders them.
     */
    public function __construct(
        public int $layerId,
        public int $year,
        public int $aadt,
        public array $paths,
        public string $eteId,
        public float $eteBeginMm,
        public float $eteEndMm,
        public ?float $beginMm,
        public ?float $endMm,
        public ?string $routeName,
        public ?string $routeNum,
        public ?string $routeType,
        public ?string $functionalClass,
        public ?string $standardRouteCode,
        public ?string $townName,
        public ?string $atrStation,
        public ?string $atrStatus,
        public ?string $locationError,
        public ?bool $isDivided,
        public ?Carbon $extractedAt,
    ) {}

    /**
     * Read one feature, or nothing when it cannot be used.
     *
     * A handful of published sections have no geometry at all, and carry a
     * location error saying so. They are dropped rather than guessed at:
     * a count that cannot be placed on a map is no use for scoring a road.
     *
     * @param  array<string, mixed>  $feature
     */
    public static function fromFeature(int $layerId, array $feature): ?self
    {
        $attributes = is_array($feature['attributes'] ?? null) ? $feature['attributes'] : [];
        $paths = self::paths($feature['geometry'] ?? null);

        $aadt = $attributes['AADT'] ?? null;
        $eteId = self::text($attributes['ETE_ID'] ?? null);
        $year = self::text($attributes['Year'] ?? null);

        if ($paths === [] || ! is_numeric($aadt) || $eteId === null || $year === null) {
            return null;
        }

        return new self(
            layerId: $layerId,
            year: (int) $year,
            aadt: (int) $aadt,
            paths: $paths,
            eteId: $eteId,
            eteBeginMm: (float) ($attributes['ETE_BeginMM'] ?? 0),
            eteEndMm: (float) ($attributes['ETE_EndMM'] ?? 0),
            beginMm: self::number($attributes['BeginMM'] ?? null),
            endMm: self::number($attributes['EndMM'] ?? null),
            routeName: self::text($attributes['RouteName'] ?? null),
            routeNum: self::text($attributes['RouteNum'] ?? null),
            routeType: self::text($attributes['RouteType'] ?? null),
            functionalClass: self::text($attributes['FunctionalClass'] ?? null),
            standardRouteCode: self::text($attributes['StandardRouteCode'] ?? null),
            townName: self::text($attributes['TownName'] ?? null),
            atrStation: self::text($attributes['ATRStation'] ?? null),
            atrStatus: self::text($attributes['ATRStatus'] ?? null),
            locationError: self::text($attributes['LOC_ERROR'] ?? null),
            isDivided: self::flag($attributes['IsDivided'] ?? null),
            extractedAt: self::timestamp($attributes['zzDateOfExtract'] ?? null),
        );
    }

    /**
     * What identifies this section, year aside: the route it is on and the
     * mileposts it runs between.
     *
     * @return array{ete_id: string, ete_begin_mm: float, ete_end_mm: float}
     */
    public function key(): array
    {
        return [
            'ete_id' => $this->eteId,
            'ete_begin_mm' => $this->eteBeginMm,
            'ete_end_mm' => $this->eteEndMm,
        ];
    }

    /**
     * The row this becomes, with the geometry as GeoJSON for PostGIS to read.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'year' => $this->year,
            'layer_id' => $this->layerId,
            'aadt' => $this->aadt,
            'geojson' => json_encode([
                'type' => 'MultiLineString',
                'coordinates' => $this->paths,
            ]),
            'ete_id' => $this->eteId,
            'ete_begin_mm' => $this->eteBeginMm,
            'ete_end_mm' => $this->eteEndMm,
            'begin_mm' => $this->beginMm,
            'end_mm' => $this->endMm,
            'route_name' => $this->routeName,
            'route_num' => $this->routeNum,
            'route_type' => $this->routeType,
            'functional_class' => $this->functionalClass,
            'standard_route_code' => $this->standardRouteCode,
            'town_name' => $this->townName,
            'atr_station' => $this->atrStation,
            'atr_status' => $this->atrStatus,
            'location_error' => $this->locationError,
            'is_divided' => $this->isDivided,
            'extracted_at' => $this->extractedAt?->toDateTimeString(),
        ];
    }

    /**
     * Esri polylines hold one path per continuous piece, and a few sections
     * come back in two or three pieces, which is why the geometry is stored
     * as a multi line string rather than a line.
     *
     * @return list<list<array{float, float}>>
     */
    private static function paths(mixed $geometry): array
    {
        if (! is_array($geometry) || ! is_array($geometry['paths'] ?? null)) {
            return [];
        }

        $paths = [];

        foreach ($geometry['paths'] as $path) {
            // A single point is not a line, and PostGIS will not build one.
            if (is_array($path) && count($path) >= 2) {
                $paths[] = array_values($path);
            }
        }

        return $paths;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function flag(mixed $value): ?bool
    {
        return match (strtoupper(trim((string) $value))) {
            'Y', 'YES', '1' => true,
            'N', 'NO', '0' => false,
            default => null,
        };
    }

    /**
     * Esri dates are milliseconds since the epoch.
     */
    private static function timestamp(mixed $value): ?Carbon
    {
        return is_numeric($value) ? Carbon::createFromTimestampMs((int) $value) : null;
    }
}
