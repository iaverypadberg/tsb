<?php

namespace App\Actions\RoadFlags;

/**
 * What a rider has picked out on the map: either a whole road, which is
 * normally several OpenStreetMap ways, or one stretch of a single way.
 */
readonly class RoadSelection
{
    /**
     * @param  list<RoadSelectionPart>  $parts
     * @param  list<array{float, float}>  $line  The whole road as one line,
     *                                           which the rider drags the
     *                                           ends of to trim it.
     */
    public function __construct(public array $parts = [], public array $line = []) {}

    public function isEmpty(): bool
    {
        return $this->parts === [];
    }

    /**
     * How much road is selected, in metres.
     */
    public function lengthMetres(): int
    {
        return array_sum(array_map(fn (RoadSelectionPart $part): int => $part->lengthMetres, $this->parts));
    }

    /**
     * @return list<int>
     */
    public function roadSegmentIds(): array
    {
        return array_values(array_unique(array_map(
            fn (RoadSelectionPart $part): int => $part->roadSegmentId,
            $this->parts,
        )));
    }

    /**
     * What a rider would call the road they picked.
     */
    public function label(): string
    {
        $labels = array_values(array_unique(array_map(
            fn (RoadSelectionPart $part): string => $part->label,
            $this->parts,
        )));

        return implode(' · ', array_slice($labels, 0, 3));
    }

    /**
     * The shape the map draws, and the only part of a selection the browser
     * ever sees.
     *
     * @return array{label: string, length_m: int, line: list<array{float, float}>, parts: list<array{id: int, coordinates: list<array{float, float}>}>}
     */
    public function forMap(): array
    {
        return [
            'label' => $this->label(),
            'length_m' => $this->lengthMetres(),
            'line' => $this->line,
            'parts' => array_map(
                fn (RoadSelectionPart $part): array => [
                    'id' => $part->roadSegmentId,
                    'coordinates' => $part->coordinates,
                ],
                $this->parts,
            ),
        ];
    }
}
