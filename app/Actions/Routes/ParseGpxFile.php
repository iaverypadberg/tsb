<?php

namespace App\Actions\Routes;

use Clickbar\Magellan\Data\Geometries\LineString;
use Clickbar\Magellan\Data\Geometries\Point;
use phpGPX\Models\GpxFile;
use phpGPX\Models\Point as GpxPoint;
use phpGPX\phpGPX;
use Throwable;

/**
 * Turns the contents of a GPX file into a route line.
 *
 * Tracks (`<trk>`) are preferred over routes (`<rte>`), since that is what
 * Ride with GPS exports; if a file has several, their points are joined in
 * document order so a multi-segment export stays a single line.
 */
class ParseGpxFile
{
    /**
     * A line needs two distinct points to exist at all.
     */
    public const MIN_POINTS = 2;

    /**
     * Guards against pathological uploads bloating the geometry column.
     */
    public const MAX_POINTS = 100_000;

    /**
     * Ignore elevation wobble below this many metres, which is GPS noise
     * rather than real climbing.
     */
    private const ELEVATION_NOISE_THRESHOLD_M = 3.0;

    /**
     * How far apart consecutive points of a <rte> may average before the file
     * is treated as a cue sheet rather than a path.
     *
     * A recorded or planned track places points every few tens of metres. A
     * Ride with GPS "GPX Route" export instead lists one point per turn,
     * which can be kilometres apart and says nothing about the roads in
     * between. Generous enough not to catch a heavily simplified track.
     */
    private const MAX_ROUTE_POINT_SPACING_M = 250.0;

    /**
     * Mean earth radius in metres, as used by the haversine formula.
     */
    private const EARTH_RADIUS_M = 6_371_008.8;

    /**
     * Parse raw GPX XML into a route.
     *
     * @throws InvalidGpxFile
     */
    public function handle(string $contents, string $fallbackName): ParsedGpxRoute
    {
        $gpx = $this->read($contents);

        ['points' => $points, 'fromTrack' => $fromTrack] = $this->collectPoints($gpx);

        if ($points === []) {
            throw InvalidGpxFile::noTrackPoints();
        }

        if (count($points) > self::MAX_POINTS) {
            throw InvalidGpxFile::tooManyPoints(self::MAX_POINTS);
        }

        $coordinates = $this->toGeodeticPoints($points);

        if (count($coordinates) < self::MIN_POINTS) {
            throw InvalidGpxFile::tooFewPoints(self::MIN_POINTS);
        }

        $this->rejectCueSheets($coordinates, $fromTrack);

        return new ParsedGpxRoute(
            name: $this->resolveName($gpx, $fallbackName),
            geometry: LineString::make($coordinates, 4326),
            elevationGainM: $this->elevationGain($points),
            pointCount: count($coordinates),
        );
    }

    /**
     * @throws InvalidGpxFile
     */
    private function read(string $contents): GpxFile
    {
        if (trim($contents) === '') {
            throw InvalidGpxFile::unreadable();
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($contents);

            // phpGPX hands malformed XML straight to SimpleXML and returns an
            // empty file rather than failing, so check the document here to
            // keep "not a GPX file" distinct from "a GPX file with no track".
            if ($xml === false || strtolower($xml->getName()) !== 'gpx') {
                throw InvalidGpxFile::unreadable();
            }

            $gpx = phpGPX::parse($contents);
        } catch (InvalidGpxFile $e) {
            throw $e;
        } catch (Throwable) {
            throw InvalidGpxFile::unreadable();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $gpx;
    }

    /**
     * Gather track points, falling back to route points.
     *
     * @return array{points: list<GpxPoint>, fromTrack: bool}
     */
    private function collectPoints(GpxFile $gpx): array
    {
        $points = [];

        foreach ($gpx->tracks as $track) {
            foreach ($track->getPoints() as $point) {
                $points[] = $point;
            }
        }

        if ($points !== []) {
            return ['points' => $points, 'fromTrack' => true];
        }

        foreach ($gpx->routes as $route) {
            foreach ($route->getPoints() as $point) {
                $points[] = $point;
            }
        }

        return ['points' => $points, 'fromTrack' => false];
    }

    /**
     * Convert to magellan points, dropping consecutive duplicates that would
     * otherwise create zero-length segments PostGIS has to carry around.
     *
     * @param  list<GpxPoint>  $points
     * @return list<Point>
     */
    private function toGeodeticPoints(array $points): array
    {
        $coordinates = [];
        $previousLatitude = null;
        $previousLongitude = null;

        foreach ($points as $point) {
            $latitude = (float) $point->latitude;
            $longitude = (float) $point->longitude;

            if (! $this->isPlausibleCoordinate($latitude, $longitude)) {
                continue;
            }

            if ($latitude === $previousLatitude && $longitude === $previousLongitude) {
                continue;
            }

            // Point::make takes (x, y), i.e. longitude before latitude. The
            // SRID is passed explicitly so parsing stays free of app config.
            $coordinates[] = Point::make($longitude, $latitude, srid: 4326);

            $previousLatitude = $latitude;
            $previousLongitude = $longitude;
        }

        return $coordinates;
    }

    private function isPlausibleCoordinate(float $latitude, float $longitude): bool
    {
        return $latitude >= -90.0 && $latitude <= 90.0
            && $longitude >= -180.0 && $longitude <= 180.0
            && ! ($latitude === 0.0 && $longitude === 0.0);
    }

    /**
     * Total metres climbed, ignoring noise below the threshold.
     *
     * Peaks are confirmed only once the track drops away from them by more
     * than the threshold, so GPS wobble does not register as climbing while a
     * long steady ascent still counts in full.
     *
     * @param  list<GpxPoint>  $points
     */
    private function elevationGain(array $points): int
    {
        $elevations = [];

        foreach ($points as $point) {
            if ($point->elevation !== null) {
                $elevations[] = (float) $point->elevation;
            }
        }

        if (count($elevations) < 2) {
            return 0;
        }

        $gain = 0.0;
        $lastConfirmed = $elevations[0];
        $candidatePeak = $elevations[0];

        foreach (array_slice($elevations, 1) as $elevation) {
            $candidatePeak = max($candidatePeak, $elevation);

            if ($candidatePeak - $elevation < self::ELEVATION_NOISE_THRESHOLD_M) {
                continue;
            }

            // The track has fallen away from the peak, so the climb up to it was real.
            $gain += $this->countableRise($candidatePeak - $lastConfirmed);

            $lastConfirmed = $elevation;
            $candidatePeak = $elevation;
        }

        // The track may simply end on a climb, with no descent to confirm it.
        $gain += $this->countableRise($candidatePeak - $lastConfirmed);

        return (int) round($gain);
    }

    /**
     * A rise only counts once it clears the noise floor.
     */
    private function countableRise(float $rise): float
    {
        return $rise >= self::ELEVATION_NOISE_THRESHOLD_M ? $rise : 0.0;
    }

    /**
     * Refuse files that only mark the turns.
     *
     * Storing one would produce a route drawn as straight lines between
     * junctions, a distance that is the sum of those straight lines rather
     * than of any road, and nothing for the road matching to work against.
     * Far better to say so than to accept it and be quietly wrong.
     *
     * @param  list<Point>  $coordinates
     *
     * @throws InvalidGpxFile
     */
    private function rejectCueSheets(array $coordinates, bool $fromTrack): void
    {
        // A <trk> is a real trace, however sparsely it was recorded.
        if ($fromTrack) {
            return;
        }

        $spacing = $this->averageSpacingMetres($coordinates);

        if ($spacing > self::MAX_ROUTE_POINT_SPACING_M) {
            throw InvalidGpxFile::cueSheetOnly((int) round($spacing));
        }
    }

    /**
     * Mean distance between consecutive points, over the ground.
     *
     * @param  list<Point>  $coordinates
     */
    private function averageSpacingMetres(array $coordinates): float
    {
        $gaps = count($coordinates) - 1;

        if ($gaps < 1) {
            return 0.0;
        }

        $total = 0.0;

        for ($i = 1; $i <= $gaps; $i++) {
            $total += $this->distanceMetres($coordinates[$i - 1], $coordinates[$i]);
        }

        return $total / $gaps;
    }

    /**
     * Great-circle distance between two points.
     */
    private function distanceMetres(Point $from, Point $to): float
    {
        $deltaLatitude = deg2rad($to->getLatitude() - $from->getLatitude());
        $deltaLongitude = deg2rad($to->getLongitude() - $from->getLongitude());

        $a = sin($deltaLatitude / 2) ** 2
            + cos(deg2rad($from->getLatitude())) * cos(deg2rad($to->getLatitude()))
            * sin($deltaLongitude / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    private function resolveName(GpxFile $gpx, string $fallbackName): string
    {
        $candidates = [
            $gpx->metadata?->name,
            $gpx->tracks[0]->name ?? null,
            $gpx->routes[0]->name ?? null,
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return mb_substr($candidate, 0, 255);
            }
        }

        return mb_substr($fallbackName, 0, 255);
    }
}
