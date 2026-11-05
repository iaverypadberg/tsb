<?php

namespace App\Actions\Aadt;

use App\Actions\RoadSegments\ScoreTrafficVolume;

/**
 * How busy a road is, as a handful of bands a rider can read off a map.
 *
 * The danger score already folds traffic into a single number, but it folds
 * everything else in too, so a road can score 70 for being a narrow numbered
 * lane and another for carrying 20,000 vehicles a day. Riders ask the second
 * question on its own — "how much traffic will I be in?" — and this answers
 * only that.
 *
 * ## Where the thresholds come from
 *
 * The measured network, not round numbers picked for looking tidy. Across
 * the 8,976 roads VTrans has counted the quartiles fall at 1,643, 3,965 and
 * 8,223 vehicles a day, with the 90th percentile at 12,785. The bands below
 * sit near those, rounded to figures a person can hold in their head, so
 * each one covers roughly a quarter of the counted network and a rider
 * moving up a band is genuinely moving into busier company.
 *
 * {@see ScoreTrafficVolume::PIVOT_AADT} is the median of the same network
 * and so lands on the Moderate/Busy boundary, which is the point of it: a
 * road at the pivot is the typical counted road.
 *
 * ## Why "not counted" is a band
 *
 * Only about one road in ten has ever been counted, so most of a rural route
 * has no figure at all. Drawing those in the quiet colour would be a claim
 * nobody has made. They get their own grey, and the key says so.
 */
enum TrafficBand: string
{
    case Quiet = 'quiet';
    case Moderate = 'moderate';
    case Busy = 'busy';
    case Heavy = 'heavy';
    case NotCounted = 'not_counted';

    /**
     * Where each band starts, in vehicles a day.
     */
    public const MODERATE_FROM = 1_000;

    public const BUSY_FROM = 4_000;

    public const HEAVY_FROM = 12_000;

    /**
     * The band a measured count falls in, or NotCounted where there is none.
     */
    public static function for(?int $aadt): self
    {
        return match (true) {
            $aadt === null || $aadt <= 0 => self::NotCounted,
            $aadt < self::MODERATE_FROM => self::Quiet,
            $aadt < self::BUSY_FROM => self::Moderate,
            $aadt < self::HEAVY_FROM => self::Busy,
            default => self::Heavy,
        };
    }

    /**
     * What the band is called in the key.
     */
    public function label(): string
    {
        return match ($this) {
            self::Quiet => __('Quiet'),
            self::Moderate => __('Moderate'),
            self::Busy => __('Busy'),
            self::Heavy => __('Heavy'),
            self::NotCounted => __('Not counted'),
        };
    }

    /**
     * The counts the band covers, said in vehicles a day.
     *
     * A colour key that only says "Busy" is one person's word against
     * another's; the numbers are what let a rider disagree with it.
     */
    public function range(): string
    {
        return match ($this) {
            self::Quiet => __('Under :count a day', ['count' => number_format(self::MODERATE_FROM)]),
            self::Moderate => __(':from–:to a day', [
                'from' => number_format(self::MODERATE_FROM),
                'to' => number_format(self::BUSY_FROM),
            ]),
            self::Busy => __(':from–:to a day', [
                'from' => number_format(self::BUSY_FROM),
                'to' => number_format(self::HEAVY_FROM),
            ]),
            self::Heavy => __('Over :count a day', ['count' => number_format(self::HEAVY_FROM)]),
            self::NotCounted => __('No count published'),
        };
    }

    /**
     * The badge colour a band is listed under.
     *
     * The blues climb with the traffic, the same ramp the map draws the line
     * in, so a card and the stretch it points at read as the same thing. A
     * road nobody has counted is off that ramp rather than at the quiet end
     * of it, so it takes the colourless badge.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Quiet => 'sky',
            self::Moderate => 'blue',
            self::Busy => 'indigo',
            self::Heavy => 'violet',
            self::NotCounted => 'zinc',
        };
    }

    /**
     * The bands in the order the key lists them, quietest first.
     *
     * @return list<self>
     */
    public static function ladder(): array
    {
        return [self::Quiet, self::Moderate, self::Busy, self::Heavy, self::NotCounted];
    }
}
