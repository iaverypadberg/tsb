<?php

namespace App\Actions\Crashes;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * One row of the Vermont crash export, read into the shape `crashes` stores.
 *
 * The export is a spreadsheet of police reports, so almost every field is
 * free text from the report form and is carried across untouched. Three
 * need actual work:
 *
 *  - `Crash Date` is written for a human ("January 15, 2015 at 5:59 AM"),
 *    not for a parser, and a few rows carry no date at all.
 *  - `Coordinates` is one field holding "latitude,longitude", which is the
 *    opposite order to the one PostGIS and GeoJSON use.
 *  - `AOT Actual Milepoint` and `Road Group ID` are numbers written as text,
 *    blank when the report did not record them.
 *
 * A row with no coordinates is still kept. It can never be matched to a
 * road, but dropping it silently would make the import totals disagree with
 * the file and hide how much of the export is unplaceable.
 */
readonly class CrashData
{
    /**
     * Where each stored column comes from in the export.
     *
     * The export's headers are the report form's wording, so they are listed
     * here once rather than spelled out at each use.
     *
     * @var array<string, string>
     */
    private const TEXT_COLUMNS = [
        'crash_type' => 'Crash Type',
        'involving' => 'Involving',
        'town' => 'City/Town',
        'address' => 'Address',
        'street_address' => 'Street Address',
        'non_reportable_address' => 'Non Reportable Address',
        'aot_route' => 'AOT Route',
        'aot_route_id' => 'AOT Route ID',
        'collision_direction' => 'Collision Direction',
        'weather' => 'Weather',
        'road_group' => 'Road Group',
        'report_number' => 'Report Number',
        'reporting_agency' => 'Reporting Agency',
        'reporting_agency_id' => 'Reporting Agency ID',
        'animal' => 'Animal',
        'time_of_day' => 'Time of Day',
        'intersection_with' => 'Intersection With',
        'impairment' => 'Impairment',
        'road_characteristics' => 'Road Characteristics',
        'road_condition' => 'Road Condition',
        'surface_condition' => 'Surface Condition',
        'mapped' => 'Mapped',
    ];

    /**
     * The shapes `Crash Date` has been seen in, most common first.
     */
    private const DATE_FORMATS = [
        'F j, Y \a\t g:i A',
        'F j, Y \a\t G:i',
        'F j, Y',
        'Y-m-d H:i:s',
        'Y-m-d',
        'n/j/Y g:i A',
        'n/j/Y',
    ];

    /**
     * @param  array<string, string|null>  $text
     */
    public function __construct(
        public int $objectId,
        public ?Carbon $occurredAt,
        public ?float $latitude,
        public ?float $longitude,
        public ?float $aotMilepoint,
        public ?int $roadGroupId,
        public array $text,
    ) {}

    /**
     * Read one exported row, or nothing when it carries no identifier.
     *
     * The `ObjectID` is what makes an import repeatable, so a row without
     * one cannot be stored: there would be no way to recognise it again.
     *
     * @param  array<string, string|null>  $row
     */
    public static function fromRow(array $row): ?self
    {
        $objectId = self::text($row['ObjectID'] ?? null);

        if ($objectId === null || ! ctype_digit($objectId)) {
            return null;
        }

        [$latitude, $longitude] = self::coordinates($row['Coordinates'] ?? null);

        $text = [];

        foreach (self::TEXT_COLUMNS as $column => $header) {
            $text[$column] = self::text($row[$header] ?? null);
        }

        return new self(
            objectId: (int) $objectId,
            occurredAt: self::occurredAt($row['Crash Date'] ?? null),
            latitude: $latitude,
            longitude: $longitude,
            aotMilepoint: self::number($row['AOT Actual Milepoint'] ?? null),
            roadGroupId: self::integer($row['Road Group ID'] ?? null),
            text: $text,
        );
    }

    /**
     * The row as `StoreCrashes` sends it to Postgres.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            ...$this->text,
            'object_id' => $this->objectId,
            'occurred_at' => $this->occurredAt?->toDateTimeString(),
            'aot_milepoint' => $this->aotMilepoint,
            'road_group_id' => $this->roadGroupId,
            // Longitude first, the order GeoJSON and PostGIS expect.
            'geojson' => $this->latitude === null || $this->longitude === null
                ? null
                : json_encode(['type' => 'Point', 'coordinates' => [$this->longitude, $this->latitude]]),
        ];
    }

    /**
     * Whether this crash can be placed on a map, and so matched to a road.
     */
    public function isPlaceable(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Read "latitude,longitude" into a pair, or nulls when it is unusable.
     *
     * Anything outside Vermont's bounding box is treated as unplaceable
     * rather than plotted: a transposed or truncated pair would otherwise
     * put a crash in the Atlantic and quietly match it to nothing.
     *
     * @return array{float|null, float|null}
     */
    private static function coordinates(?string $coordinates): array
    {
        $parts = explode(',', (string) $coordinates);

        if (count($parts) !== 2) {
            return [null, null];
        }

        $latitude = self::number($parts[0]);
        $longitude = self::number($parts[1]);

        if ($latitude === null || $longitude === null) {
            return [null, null];
        }

        $inVermont = $latitude > 42.6 && $latitude < 45.1
            && $longitude > -73.6 && $longitude < -71.4;

        return $inVermont ? [$latitude, $longitude] : [null, null];
    }

    /**
     * Parse the exported date, trying each shape it has been published in.
     */
    private static function occurredAt(?string $date): ?Carbon
    {
        $date = self::text($date);

        if ($date === null) {
            return null;
        }

        // Most specific format first: a looser one would match the date and
        // quietly discard the time after it.
        foreach (self::DATE_FORMATS as $format) {
            try {
                return Carbon::createFromFormat($format, $date);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function number(?string $value): ?float
    {
        $value = self::text($value);

        return $value === null || ! is_numeric($value) ? null : (float) $value;
    }

    private static function integer(?string $value): ?int
    {
        $number = self::number($value);

        return $number === null ? null : (int) $number;
    }
}
