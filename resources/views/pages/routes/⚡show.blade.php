<?php

use App\Actions\Aadt\TrafficBand;
use App\Actions\Crashes\SummariseCrashesAlongRoute;
use Flux\Flux;
use App\Jobs\AnalyseRoute;
use App\Jobs\ProfileRouteTraffic;
use App\Models\FlaggedSection;
use App\Models\Route;
use App\Models\RouteAnalysis;
use App\Models\TrafficSection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Route $route;

    // TEMP: danger scoring is hidden until it is integrated back in.
    // /**
    //  * How many analyses one rider may start per minute.
    //  */
    // public const ANALYSES_PER_MINUTE = 5;
    //
    // /**
    //  * How much traffic the rider is willing to put up with.
    //  */
    // public string $riskTolerance = RouteAnalysis::TOLERANCE_NORMAL;

    /**
     * The map's colourings, and the list that belongs to each.
     */
    // TEMP: danger scoring is hidden until it is integrated back in.
    // public const COLOUR_DANGER = 'danger';

    public const COLOUR_TRAFFIC = 'traffic';

    /**
     * Which colouring is on the map, and so which list is beside it.
     *
     * TEMP: pinned to the traffic while the danger scoring is hidden. There
     * is only the one colouring to show, so there is nothing to pick
     * between and nothing to remember a pick of.
     */
    public string $colourBy = self::COLOUR_TRAFFIC;

    // TEMP: danger scoring is hidden until it is integrated back in.
    // /**
    //  * Whether the rider has picked a colouring themselves.
    //  *
    //  * Until they have, it follows what there is to show: the traffic on a
    //  * freshly uploaded route, the flags once an analysis has something to
    //  * say. Once they have chosen, their choice stands.
    //  */
    // public bool $colourChosen = false;

    /**
     * The roads in the order the route rides them.
     *
     * Where a rider starts: the list beside the map reads the same way the
     * map does, so a stretch they are looking at on the line is the card at
     * the same place down the list. The rankings below answer a different
     * question, and a rider asks it once they know what the ride looks
     * like.
     */
    public const SORT_ROUTE = 'route';

    /**
     * Busiest road first.
     */
    public const SORT_TRAFFIC = 'traffic';

    /**
     * Fastest posted limit first.
     *
     * The other half of what a rider is choosing against. How much traffic
     * there is and how fast it is going are different questions, and the
     * count cannot answer the second: a lane carrying 800 vehicles a day at
     * 50 mph is not the same ride as one carrying 800 at 25.
     *
     * Only about one Vermont way in twenty is tagged with a limit, so most
     * stretches have nothing to rank on here and sort below the ones that
     * do, the same way an uncounted road does under `Busiest`.
     */
    public const SORT_SPEED = 'speed';

    /**
     * Where people have been hurt first.
     *
     * Injuries rather than deaths: 187 of the 32,000 reported crashes here
     * were fatal, so ranking on them would sort most of a route on nought
     * against nought and hand the top of the list to whichever road happens
     * to carry the one record. Injuries are common enough to separate one
     * road from the next.
     */
    public const SORT_INJURIES = 'injuries';

    /**
     * Where the most has been reported at all, whatever came of it.
     */
    public const SORT_CRASHES = 'crashes';

    /**
     * Where somebody on a bicycle or on foot was in it first.
     */
    public const SORT_VULNERABLE = 'vulnerable';

    /**
     * How the traffic list may be ordered, and what the control calls each.
     *
     * @var array<string, string>
     */
    public const TRAFFIC_SORTS = [
        self::SORT_ROUTE => 'Route order',
        self::SORT_TRAFFIC => 'Busiest',
        self::SORT_SPEED => 'Fastest',
        self::SORT_INJURIES => 'Most injuries',
        self::SORT_CRASHES => 'Most crashes',
        self::SORT_VULNERABLE => 'Most Bike & ped crashes',
    ];

    /**
     * Stretches shorter than this are left out of the traffic list.
     *
     * The list is a ranking, not an inventory: the map still colours every
     * stretch, but a route clipping thirty metres of a main road where it
     * crosses a junction has not ridden that road, and sorting by traffic
     * would otherwise put that clip at the top of the list.
     */
    public const MIN_LISTED_LENGTH_M = 50;

    /**
     * Which way the traffic list is ordered.
     */
    public string $trafficSort = self::SORT_ROUTE;

    /**
     * Open the route up to anyone holding its link.
     *
     * The public id in the URL is already unguessable, so there is no second
     * address to mint: sharing is this column, and withdrawing it is clearing
     * the column again.
     */
    public function share(): void
    {
        $this->authorize('share', $this->route);

        $this->route->update(['shared_at' => now()]);

        Flux::toast(variant: 'success', text: __('Anyone with the link can now see this route.'));
    }

    /**
     * Close it again. The link stops working for everyone who has it.
     */
    public function stopSharing(): void
    {
        $this->authorize('share', $this->route);

        $this->route->update(['shared_at' => null]);

        Flux::toast(text: __('The link no longer works.'));
    }

    /**
     * The link to hand to someone else.
     *
     * The same URL the owner is reading, because a shared route is the same
     * page seen by someone who does not own it.
     */
    #[Computed(persist: false)]
    public function shareUrl(): string
    {
        return route('routes.show', $this->route);
    }

    /**
     * Whether the person reading the page is the one who may hand it out.
     */
    #[Computed(persist: false)]
    public function canShare(): bool
    {
        return Gate::allows('share', $this->route);
    }

    public function mount(Route $route): void
    {
        $this->authorize('view', $route);

        $this->route = $route;

        // TEMP: danger scoring is hidden until it is integrated back in.
        // $this->riskTolerance = $this->analysis?->risk_tolerance
        //     ?? Auth::user()->risk_tolerance
        //     ?: RouteAnalysis::TOLERANCE_NORMAL;

        $this->profileTrafficIfNeeded();
    }

    /**
     * Queue the traffic profile for a route uploaded before there was one.
     *
     * Marking it queued in the same breath as dispatching is what stops a
     * second view, or the poll below, piling up another job for work that is
     * already in hand.
     */
    private function profileTrafficIfNeeded(): void
    {
        // Someone who was sent the link is reading the route, not keeping it.
        // Work on it belongs to whoever uploaded it.
        if (! Gate::allows('share', $this->route)) {
            return;
        }

        if ($this->route->traffic_status !== Route::TRAFFIC_UNPROFILED) {
            return;
        }

        $this->route->update(['traffic_status' => Route::TRAFFIC_QUEUED]);

        ProfileRouteTraffic::dispatch($this->route);
    }

    /**
     * Name the browser tab after the route.
     *
     * The #[Title] attribute only takes a literal, so the title is set on the
     * view instead.
     */
    public function rendering(View $view): void
    {
        $view->title($this->route->name);

        // TEMP: danger scoring is hidden until it is integrated back in.
        // The colouring follows what there is to show until the rider picks
        // one, and falls back whenever their pick has nothing left to show:
        // asking again for an analysis takes the flags away until it
        // finishes, and a rider sitting on the flags would otherwise be
        // left with a list of one colouring beside a map showing the other.
        // if (! $this->colourChosen || ! $this->canColourBy($this->colourBy)) {
        //     $this->colourBy = $this->defaultColourBy;
        // }
    }

    // TEMP: danger scoring is hidden until it is integrated back in.
    // /**
    //  * Whether there is anything to draw in a given colouring.
    //  */
    // private function canColourBy(string $colouring): bool
    // {
    //     return $colouring === self::COLOUR_TRAFFIC
    //         ? $this->hasTraffic
    //         : $this->isComplete;
    // }
    //
    // /**
    //  * Hold the colouring to one the map knows, and remember that the rider
    //  * picked it.
    //  */
    // public function updatedColourBy(string $value): void
    // {
    //     $this->colourBy = in_array($value, [self::COLOUR_DANGER, self::COLOUR_TRAFFIC], true)
    //         ? $value
    //         : self::COLOUR_DANGER;
    //
    //     $this->colourChosen = true;
    // }
    //
    // /**
    //  * Queue a fresh look at this route.
    //  */
    // public function analyse(): void
    // {
    //     $this->authorize('view', $this->route);
    //
    //     $this->validate([
    //         'riskTolerance' => ['required', 'in:'.implode(',', array_keys(RouteAnalysis::THRESHOLDS))],
    //     ]);
    //
    //     // Each run is a couple of seconds of spatial work against every road
    //     // in the region, so it is worth holding someone to a sane pace.
    //     if (RateLimiter::tooManyAttempts($this->rateLimitKey(), self::ANALYSES_PER_MINUTE)) {
    //         $this->addError('riskTolerance', __('That is a lot of analysing. Try again in :seconds seconds.', [
    //             'seconds' => RateLimiter::availableIn($this->rateLimitKey()),
    //         ]));
    //
    //         return;
    //     }
    //
    //     RateLimiter::increment($this->rateLimitKey());
    //
    //     $analysis = $this->route->analyses()->create([
    //         'status' => RouteAnalysis::STATUS_PENDING,
    //         'risk_tolerance' => $this->riskTolerance,
    //     ]);
    //
    //     AnalyseRoute::dispatch($analysis);
    //
    //     unset($this->analysis, $this->flaggedSections, $this->unflaggedSections);
    // }
    //
    // /**
    //  * Rate limiting is per rider, not per route: the cost is the same
    //  * whichever route is being analysed.
    //  */
    // private function rateLimitKey(): string
    // {
    //     return 'analyse-route:'.Auth::id();
    // }

    // TEMP: danger scoring is hidden until it is integrated back in.
    // /**
    //  * How much of the road data carries each tag, as whole percentages.
    //  *
    //  * Cached: it changes only when an extract is imported, and the honesty
    //  * of the explanation is worth a query an hour.
    //  *
    //  * @return array<string, int>
    //  */
    // #[Computed(persist: false)]
    // public function tagCoverage(): array
    // {
    //     return Cache::remember('road-segments.tag-coverage', now()->addHour(), function (): array {
    //         $row = DB::selectOne(
    //             'select
    //                 count(*) as total,
    //                 count(speed_limit_kmh) as speed,
    //                 count(surface) as surface,
    //                 count(has_shoulder) as shoulder,
    //                 count(aadt) as traffic,
    //                 count(case when crash_count > 0 then 1 end) as crashes
    //              from road_segments',
    //         );
    //
    //         if ($row === null || (int) $row->total === 0) {
    //             return [];
    //         }
    //
    //         $share = fn (int $count): int => (int) round(100 * $count / (int) $row->total);
    //
    //         return [
    //             'speed' => $share((int) $row->speed),
    //             'surface' => $share((int) $row->surface),
    //             'shoulder' => $share((int) $row->shoulder),
    //             'traffic' => $share((int) $row->traffic),
    //             'crashes' => $share((int) $row->crashes),
    //         ];
    //     });
    // }
    //
    // /**
    //  * The score at which the chosen setting starts flagging a road.
    //  */
    // #[Computed(persist: false)]
    // public function threshold(): int
    // {
    //     return RouteAnalysis::THRESHOLDS[$this->riskTolerance]
    //         ?? RouteAnalysis::THRESHOLDS[RouteAnalysis::TOLERANCE_NORMAL];
    // }
    //
    // /**
    //  * The most recent analysis, if the route has ever been looked at.
    //  */
    // #[Computed(persist: false)]
    // public function analysis(): ?RouteAnalysis
    // {
    //     return $this->route->analyses()->latest('id')->first();
    // }
    //
    // /**
    //  * @return Collection<int, FlaggedSection>
    //  */
    // #[Computed(persist: false)]
    // public function flaggedSections(): Collection
    // {
    //     if ($this->analysis?->status !== RouteAnalysis::STATUS_COMPLETED) {
    //         return collect();
    //     }
    //
    //     return $this->analysis->flaggedSections()->orderBy('start_fraction')->get();
    // }
    //
    // /**
    //  * The stretches between the flags, in the order they are ridden.
    //  *
    //  * Listed below the flagged ones so the rider can see the whole route
    //  * accounted for: a flag list on its own cannot say whether three
    //  * warnings are most of a ride or three blemishes on it.
    //  *
    //  * @return Collection<int, FlaggedSection>
    //  */
    // #[Computed(persist: false)]
    // public function unflaggedSections(): Collection
    // {
    //     if ($this->analysis?->status !== RouteAnalysis::STATUS_COMPLETED) {
    //         return collect();
    //     }
    //
    //     return $this->analysis->unflaggedSections()->orderBy('start_fraction')->get();
    // }

    /**
     * Whether the page should keep checking for a job to finish.
     */
    #[Computed(persist: false)]
    public function isWorking(): bool
    {
        // TEMP: danger scoring is hidden until it is integrated back in.
        // return ($this->analysis !== null && ! $this->analysis->isFinished())
        //     || $this->route->isProfilingTraffic();

        return $this->route->isProfilingTraffic();
    }

    // TEMP: danger scoring is hidden until it is integrated back in.
    // /**
    //  * Whether there are results worth showing beside the map.
    //  */
    // #[Computed(persist: false)]
    // public function isComplete(): bool
    // {
    //     return $this->analysis?->status === RouteAnalysis::STATUS_COMPLETED;
    // }

    /**
     * @return list<array{float, float}>
     */
    #[Computed(persist: false)]
    public function coordinates(): array
    {
        return $this->route->coordinatesForMap();
    }

    /**
     * How much traffic the route carries, stretch by stretch.
     *
     * Unlike the flagged sections this does not wait for an analysis: it is
     * worked out when the route is uploaded, so it is the one thing the map
     * can colour the moment a rider arrives.
     *
     * @return Collection<int, TrafficSection>
     */
    #[Computed(persist: false)]
    public function trafficSections(): Collection
    {
        return $this->route->trafficSections()->orderBy('start_fraction')->orderBy('id')->get();
    }

    /**
     * The traffic stretches the list shows, in the order it shows them.
     *
     * In route order this is the ride as the map draws it, minus the
     * stretches with nothing to say for themselves. Under any of the
     * rankings it is a league table instead, worst first, which is the
     * question a rider asks of a route once they have seen its shape: what
     * is the worst of it, and how much of it is there.
     *
     * @return Collection<int, TrafficSection>
     */
    #[Computed(persist: false)]
    public function rankedTrafficSections(): Collection
    {
        $crashes = $this->trafficCrashes;

        $listed = $this->trafficSections
            ->filter(fn (TrafficSection $section): bool => $this->isWorthListing($section, $crashes));

        if ($this->trafficSort === self::SORT_ROUTE) {
            return $listed->values();
        }

        return $listed
            ->sortByDesc(fn (TrafficSection $section): array => $this->rankOf($section, $crashes[$section->id] ?? []))
            ->values();
    }

    /**
     * Whether a stretch has anything to say for itself.
     *
     * A stretch nobody has counted and nothing has been reported on carries
     * no figure, no band worth the name and no crash — a card for it would
     * say only "Not counted" beside a distance. Most of a rural route is
     * made of those, and listing them buries the roads that do have
     * something to show under a page of blanks.
     *
     * One or the other is enough: an uncounted lane where two people have
     * been hurt belongs on the list, and so does a counted road with a
     * clean record. Only having neither drops it.
     *
     * The map still draws every stretch, uncounted ones included. It has to:
     * the colouring is of the whole ride, and a gap in the line would read
     * as a colour of its own. This is a ranking, not an inventory.
     *
     * @param  array<int, array{total: int, fatal: int, injury: int, bicycle: int, pedestrian: int}>  $crashes
     */
    private function isWorthListing(TrafficSection $section, array $crashes): bool
    {
        if ($section->length_m < self::MIN_LISTED_LENGTH_M) {
            return false;
        }

        return $section->aadt !== null || isset($crashes[$section->id]);
    }

    /**
     * What the chosen ordering compares one stretch to the next on, most
     * significant figure first.
     *
     * @param  array{total?: int, fatal?: int, injury?: int, bicycle?: int, pedestrian?: int}  $crashes
     * @return list<int>
     */
    private function rankOf(TrafficSection $section, array $crashes): array
    {
        $total = (int) ($crashes['total'] ?? 0);
        $injury = (int) ($crashes['injury'] ?? 0);
        $vulnerable = (int) ($crashes['bicycle'] ?? 0) + (int) ($crashes['pedestrian'] ?? 0);

        // A road nobody has counted is not a quiet road, but it cannot be
        // ranked against one that has a figure either, so it sorts below
        // every count rather than being read as nought vehicles a day. An
        // unposted limit is the same: untagged, not derestricted.
        $aadt = $section->aadt ?? -1;
        $speed = $section->speed_limit_kmh ?? -1;

        return match ($this->trafficSort) {
            self::SORT_SPEED => [$speed, $aadt, $total],
            self::SORT_INJURIES => [$injury, $total, $aadt],
            self::SORT_CRASHES => [$total, $injury, $aadt],
            self::SORT_VULNERABLE => [$vulnerable, $injury, $aadt],
            default => [$aadt, $section->length_m, $total],
        };
    }

    /**
     * Keep the ordering to one the list knows, whatever arrives.
     */
    public function updatedTrafficSort(string $value): void
    {
        if (! array_key_exists($value, self::TRAFFIC_SORTS)) {
            $this->trafficSort = self::SORT_ROUTE;
        }
    }

    /**
     * The orderings the control offers, in the words it offers them.
     *
     * @return array<string, string>
     */
    #[Computed(persist: false)]
    public function trafficSortOptions(): array
    {
        return array_map(fn (string $label): string => __($label), self::TRAFFIC_SORTS);
    }

    /**
     * What has been reported beside each stretch, keyed by stretch.
     *
     * @return array<int, array{total: int, fatal: int, injury: int, bicycle: int, pedestrian: int}>
     */
    #[Computed(persist: false)]
    public function trafficCrashes(): array
    {
        if (! $this->hasTraffic) {
            return [];
        }

        return app(SummariseCrashesAlongRoute::class)->handle($this->route);
    }

    /**
     * Whether there is a traffic profile to colour the route with.
     */
    #[Computed(persist: false)]
    public function hasTraffic(): bool
    {
        return $this->trafficSections->isNotEmpty();
    }

    /**
     * The traffic stretches, shaped for the map.
     *
     * The road's name leads the tooltip where there is one, so that hovering
     * the line answers the same question the list does: which road is this.
     *
     * @return list<array{id: int, band: string, aadt: int|null, label: string, coordinates: list<array{float, float}>}>
     */
    #[Computed(persist: false)]
    public function trafficForMap(): array
    {
        return $this->trafficSections
            ->map(fn (TrafficSection $section): array => [
                'id' => $section->id,
                'band' => $section->band->value,
                'aadt' => $section->aadt,
                'label' => implode(' · ', array_filter([
                    $section->roadLabel(),
                    $section->aadt === null
                        ? $section->band->label()
                        : __(':count vehicles a day', ['count' => number_format($section->aadt)]),
                ])),
                'coordinates' => $section->coordinatesForMap(),
            ])
            ->all();
    }

    /**
     * The colour key for the traffic overlay: only the bands this route
     * actually runs on, quietest first, with how far it runs on each.
     *
     * The distances are the honest part. A key that says "Heavy" tells a
     * rider the colour exists; one that says "Heavy — 2.4 km" tells them
     * what their ride is made of.
     *
     * @return list<array{band: string, label: string, range: string, km: float}>
     */
    #[Computed(persist: false)]
    public function trafficKey(): array
    {
        $byBand = $this->trafficSections->groupBy(fn (TrafficSection $section): string => $section->band->value);

        return array_values(array_filter(array_map(
            fn (TrafficBand $band): ?array => $byBand->has($band->value) ? [
                'band' => $band->value,
                'label' => $band->label(),
                'range' => $band->range(),
                'km' => round($byBand[$band->value]->sum('length_m') / 1000, 1),
            ] : null,
            TrafficBand::ladder(),
        )));
    }

    /**
     * What the ride is made of: how far it runs on each sort of road.
     *
     * Roads nobody has counted are read as quiet. That is a decision about
     * the world rather than about the data: in Vermont the counted network is
     * the numbered highways and the through roads, and what is left over is
     * dirt lanes and back roads. A rider who finds themselves on one is not
     * in traffic. Banding them as Quiet is the assumption a rider makes
     * anyway, so the chart makes it out loud instead of leaving a grey wedge
     * for them to interpret.
     *
     * It is still an assumption, so the figure behind it is kept and said
     * beneath the bar. A route that is nine tenths uncounted is reading as
     * nine tenths quiet on the strength of that assumption, and whoever is
     * looking at it should be able to see how much of the claim rests there.
     *
     * The total is the summed length of the stretches rather than the route's
     * own distance, because those are not the same number — a route runs on
     * roads only for the part of it that could be matched to one — and a bar
     * of shares that do not add up to its own total is a broken bar.
     *
     * @return array{
     *     km: float,
     *     roads: int,
     *     uncounted: array{km: float, roads: int},
     *     bands: list<array{band: string, label: string, range: string, shortRange: string, km: float, roads: int, percent: float, color: string}>,
     * }
     */
    #[Computed(persist: false)]
    public function trafficBreakdown(): array
    {
        $byBand = $this->trafficSections->groupBy(
            fn (TrafficSection $section): string => $section->band->value,
        );

        $metres = fn (TrafficBand $band): float => $byBand->has($band->value) ? (float) $byBand[$band->value]->sum('length_m') : 0.0;
        $roads = fn (TrafficBand $band): int => $byBand->has($band->value) ? $byBand[$band->value]->count() : 0;

        // Every band, whether this route touches it or not. A key that lists
        // only the bands present is a key that changes shape from route to
        // route, and a rider comparing two of them is then comparing two
        // different charts. An empty band is a finding of its own: no busy
        // road on this ride at all.
        $bands = array_values(array_filter(
            TrafficBand::ladder(),
            fn (TrafficBand $band): bool => $band !== TrafficBand::NotCounted,
        ));

        $uncountedMetres = $metres(TrafficBand::NotCounted);
        $uncountedRoads = $roads(TrafficBand::NotCounted);

        $totalMetres = array_sum(array_map($metres, $bands)) + $uncountedMetres;

        if ($totalMetres <= 0) {
            return [
                'km' => 0.0,
                'roads' => 0,
                'uncounted' => ['km' => 0.0, 'roads' => 0],
                'bands' => [],
            ];
        }

        return [
            'km' => round($totalMetres / 1000, 1),
            'roads' => array_sum(array_map($roads, $bands)) + $uncountedRoads,
            'uncounted' => [
                'km' => round($uncountedMetres / 1000, 1),
                'roads' => $uncountedRoads,
            ],
            'bands' => array_map(
                function (TrafficBand $band) use ($metres, $roads, $uncountedMetres, $uncountedRoads, $totalMetres): array {
                    $isQuiet = $band === TrafficBand::Quiet;

                    $bandMetres = $metres($band) + ($isQuiet ? $uncountedMetres : 0.0);
                    $bandRoads = $roads($band) + ($isQuiet ? $uncountedRoads : 0);

                    return [
                        'band' => $band->value,
                        'label' => $band->label(),
                        'range' => $band->range(),
                        'shortRange' => $band->shortRange(),
                        'km' => round($bandMetres / 1000, 1),
                        'roads' => $bandRoads,
                        'percent' => round(100 * $bandMetres / $totalMetres, 1),
                        'color' => $band->color(),
                    ];
                },
                $bands,
            ),
        ];
    }

    /**
     * Whether the traffic colouring is the one on the map.
     */
    #[Computed(persist: false)]
    public function isColouredByTraffic(): bool
    {
        return $this->colourBy === self::COLOUR_TRAFFIC;
    }

    // TEMP: danger scoring is hidden until it is integrated back in.
    // /**
    //  * Which colouring the map opens on.
    //  *
    //  * Traffic until there is an analysis to show, which is the whole point
    //  * of profiling it up front: a freshly uploaded route has something to
    //  * say for itself before anybody has pressed anything.
    //  */
    // #[Computed(persist: false)]
    // public function defaultColourBy(): string
    // {
    //     return $this->isComplete ? self::COLOUR_DANGER : self::COLOUR_TRAFFIC;
    // }
    //
    // /**
    //  * Every stretch, shaped for the map.
    //  *
    //  * The unflagged ones are drawn too, in the safe colour, so that clicking
    //  * any card in either list has something on the map to zoom to and every
    //  * part of the line can be hovered for what it is.
    //  *
    //  * @return list<array{id: int, danger: int, flagged: bool, label: string, coordinates: list<array{float, float}>}>
    //  */
    // #[Computed(persist: false)]
    // public function sectionsForMap(): array
    // {
    //     return $this->flaggedSections
    //         ->concat($this->unflaggedSections)
    //         ->map(fn (FlaggedSection $section): array => [
    //             'id' => $section->id,
    //             'danger' => $section->max_danger,
    //             'flagged' => $section->flagged,
    //             'label' => implode(' · ', $section->reasons ?? []),
    //             'coordinates' => $section->coordinatesForMap(),
    //         ])
    //         ->all();
    // }
    //
    // /**
    //  * How much of the route the rider was warned about.
    //  */
    // #[Computed(persist: false)]
    // public function flaggedShare(): int
    // {
    //     $flagged = (int) ($this->analysis?->summary['flagged_m'] ?? 0);
    //
    //     return $this->route->distance_m > 0
    //         ? (int) round(100 * $flagged / $this->route->distance_m)
    //         : 0;
    // }

}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 pb-24 sm:pb-40" @if ($this->isWorking) wire:poll.2s @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">
                {{ __('Average Daily Traffic(ADT) Map') }}
                {{ "-" }}
                {{ $route->name }}
            
            </flux:heading>
            <flux:text class="mt-1">
                {{ __('Uploaded :when', ['when' => $route->created_at->diffForHumans()]) }}
            </flux:text>
        </div>

        @auth
            <flux:button :href="route('routes.index')" variant="subtle" icon="arrow-left" wire:navigate>
                {{ __('All routes') }}
            </flux:button>
        @else
            <flux:button :href="route('routes.index')" variant="subtle" icon="arrow-up-tray" wire:navigate>
                {{ __('Upload another') }}
            </flux:button>
        @endauth
    </div>

    {{-- When a route goes away is the one thing neither the person who
         uploaded it nor the person they sent it to can be left to find out
         for themselves. The owner is told what to do about it; a recipient is
         only told the date, since it is not theirs to keep. --}}
    @can('share', $route)
        @if ($route->isGuestUpload())
            <flux:callout icon="user-plus" color="blue" data-test="claim-prompt">
                <flux:callout.heading>{{ __('Create an account to keep this route') }}</flux:callout.heading>

                <flux:callout.text>
                    {{ __('This route expires :when, on :date. Sign up to keep the route and use our road flagging feature.', [
                        'when' => $route->expires_at->diffForHumans(),
                        'date' => $route->expires_at->toFormattedDateString(),
                    ]) }}
                </flux:callout.text>

                <x-slot name="actions">
                    <flux:button :href="route('register')" variant="primary" size="sm" data-test="claim-register">
                        {{ __('Create an account') }}
                    </flux:button>

                    <flux:button :href="route('login')" variant="ghost" size="sm">
                        {{ __('Log in') }}
                    </flux:button>
                </x-slot>
            </flux:callout>
        @endif
    @else
        @if ($route->expires_at !== null)
            <flux:callout icon="clock" color="amber" data-test="expiry-notice">
                <flux:callout.heading>{{ __('This link has a time limit') }}</flux:callout.heading>

                <flux:callout.text>
                    {{ __('The route expires :when, on :date, unless whoever uploaded it creates an account to keep it.', [
                        'when' => $route->expires_at->diffForHumans(),
                        'date' => $route->expires_at->toFormattedDateString(),
                    ]) }}
                </flux:callout.text>
            </flux:callout>
        @endif
    @endcan

    {{-- <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <dt class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Distance') }}</dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($route->distanceKm(), 1) }} km</dd>
        </div>
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <dt class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Climbing') }}</dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($route->elevation_gain_m) }} m</dd>
        </div>
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <dt class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Points') }}</dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($route->point_count) }}</dd>
        </div>
        TEMP: danger scoring is hidden until it is integrated back in.
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <dt class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Flagged') }}</dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums" data-test="flagged-share">
                @if ($this->analysis?->status === \App\Models\RouteAnalysis::STATUS_COMPLETED)
                    {{ $this->flaggedShare }}%
                @else
                    &mdash;
                @endif
            </dd>
        </div>
       
    </dl> --}}

    {{-- TEMP: danger scoring is hidden until it is integrated back in.
    <div class="flex flex-wrap items-end gap-4 rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
        <flux:select
            wire:model="riskTolerance"
            :label="__('Risk tolerance')"
            :description="__('Flags roads scoring :score or more out of 100.', ['score' => $this->threshold])"
            class="max-w-xs"
        >
            <flux:select.option value="cautious">{{ __('Cautious — flag anything busy') }}</flux:select.option>
            <flux:select.option value="normal">{{ __('Normal — flag busy roads') }}</flux:select.option>
            <flux:select.option value="brave">{{ __('Brave — only flag the worst') }}</flux:select.option>
        </flux:select>

        <flux:button
            wire:click="analyse"
            variant="primary"
            icon="magnifying-glass"
            :disabled="$this->isWorking"
            data-test="analyse-button"
        >
            {{ $this->analysis ? __('Analyse again') : __('Analyse route') }}
        </flux:button>

        @if ($this->isWorking)
            <flux:text class="flex items-center gap-2 text-sm" data-test="analysis-working">
                <flux:icon.loading class="size-4" />
                {{ __('Checking the roads on this route…') }}
            </flux:text>
        @endif
    </div>

    <x-scoring-explainer :coverage="$this->tagCoverage" data-test="scoring-explainer" />

    @if ($this->analysis?->status === \App\Models\RouteAnalysis::STATUS_FAILED)
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('That analysis did not finish')">
            {{ $this->analysis->failure_reason ?: __('Something went wrong. Try again.') }}
        </flux:callout>
    @endif
    --}}

    <div class="grid gap-4 @if ($this->hasTraffic || $this->canShare) lg:grid-cols-3 @endif" data-test="map-layout">
        <div class="flex flex-col gap-3 lg:col-span-2">
            {{-- The colouring is a server property, not an Alpine one, because
                 the list beside the map is of whichever colouring is on: it
                 has to be rendered, not merely shown. The map itself is left
                 out of the morph below, so switching redraws its lines
                 without rebuilding it or losing where the rider had panned. --}}
            {{-- TEMP: danger scoring is hidden until it is integrated back in.
            @if ($this->hasTraffic && $this->isComplete)
                <flux:radio.group wire:model.live="colourBy" variant="segmented" size="sm" class="self-start" data-test="colour-by">
                    <flux:radio value="danger" icon="exclamation-triangle">{{ __('Danger') }}</flux:radio>
                    <flux:radio value="traffic" icon="truck">{{ __('Traffic') }}</flux:radio>
                </flux:radio.group>
            @elseif ($this->hasTraffic)
            --}}
            @if ($this->hasTraffic)

            <flux:text class="text-sm" data-test="traffic-only">
                {{ __("The more grey that's on your route the better!") }}
            </flux:text>
            @elseif ($this->route->isProfilingTraffic())
                <flux:text class="flex items-center gap-2 text-sm" data-test="traffic-working">
                    <flux:icon.loading class="size-4" />
                    {{ __('Working out the traffic on this route…') }}
                </flux:text>
            @elseif ($this->route->traffic_status === \App\Models\Route::TRAFFIC_FAILED)
                <flux:text class="text-sm" data-test="traffic-failed">
                    {{ __('The traffic on this route could not be worked out.') }}
                </flux:text>
            @endif

            {{-- Left out of the morph entirely: Leaflet fills this with its
                 own panes and layers, and the server's idea of it is an
                 empty div. The key carries everything the map is drawn
                 from, so it is rebuilt when, and only when, that changes. --}}
            <div
                wire:ignore
                {{-- TEMP: danger scoring is hidden until it is integrated back in. The second argument was
                     @js($this->sectionsForMap), and the key carried the
                     analysis id and the section count alongside. --}}
                x-data="routeMap(@js($this->coordinates), @js([]), @js($this->trafficForMap), @js(['key' => $this->trafficKey, 'colourBy' => $this->colourBy]))"
                wire:key="map-{{ count($this->trafficForMap) }}"
                data-test="route-map"
            >
                <div
                    x-ref="map"
                    class="h-[24rem] w-full overflow-hidden rounded-xl border border-neutral-200 sm:h-[38rem] dark:border-neutral-700"
                ></div>
            </div>

            {{-- Beneath the map and inside its column, because it is the
                 map's own figures counted up: a reader meets the ride first
                 and then what it adds up to, and the bar lines up with the
                 line it is a tally of. In the column rather than in a grid of
                 its own so that the two stay the same width at every
                 breakpoint without anything being kept in step by hand. --}}
            @if ($this->hasTraffic)
                <x-traffic-breakdown :breakdown="$this->trafficBreakdown" class="mt-1" />
            @endif
        </div>

        {{-- The column beside the map: the share box, then the list of
             whichever colouring is on the map — the flags when the route is
             coloured by danger, the roads it runs on when it is coloured by
             traffic. The column is held to the map's height so the two end
             together, and only the list inside it scrolls. --}}
        @if ($this->hasTraffic || $this->canShare)
            <div class="flex min-h-0 flex-col gap-4 lg:h-[38rem]">
                {{-- Sharing is the owner's to give and to take back. --}}
                @can('share', $route)
                    <div
                        class="flex shrink-0 flex-col gap-2 rounded-xl border border-neutral-200 p-3 dark:border-neutral-700"
                        data-test="share-panel"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <flux:heading level="2">{{ __('Share this route') }}</flux:heading>

                            @if ($route->isShared())
                                <flux:button wire:click="stopSharing" variant="subtle" size="sm" icon="link-slash" data-test="stop-sharing">
                                    {{ __('Stop sharing') }}
                                </flux:button>
                            @else
                                <flux:button wire:click="share" variant="primary" size="sm" icon="link" data-test="share-route">
                                    {{ __('Create a share link') }}
                                </flux:button>
                            @endif
                        </div>

                        @if ($route->isShared())
                            {{-- The confirmation is a toast rather than a swapped
                                 label: this page re-renders itself every two
                                 seconds while the traffic is being worked out,
                                 and Livewire's morph would take any Alpine-held
                                 state with it. --}}
                            <div class="flex items-start gap-2" x-data>
                                <flux:input
                                    readonly
                                    size="sm"
                                    :value="$this->shareUrl"
                                    class="font-mono text-xs"
                                    data-test="share-url"
                                    x-on:focus="$el.select()"
                                />

                                <flux:button
                                    variant="filled"
                                    size="sm"
                                    icon="clipboard"
                                    x-on:click="
                                        navigator.clipboard.writeText(@js($this->shareUrl));
                                        $dispatch('toast-show', {
                                            duration: 3000,
                                            slots: { text: @js(__('Link copied.')) },
                                            dataset: { variant: 'success' },
                                        });
                                    "
                                >
                                    {{ __('Copy') }}
                                </flux:button>
                            </div>
                        @endif
                    </div>
                @endcan

                {{-- data-scroll-panel: the map scrolls this column to the card
                     for a road clicked on it, and scrolls the column rather
                     than the page so the map stays where the rider can see it. --}}
                @if ($this->hasTraffic)
                    <div
                        class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto pr-1"
                        data-scroll-panel
                        data-test="section-panel"
                    >
                {{-- TEMP: danger scoring is hidden until it is integrated back in.
                @if (! $this->isColouredByTraffic)
                    <div class="flex flex-col gap-4" data-test="danger-panel">
                        <div class="flex flex-col gap-3">
                            <flux:heading size="lg">
                                {{ trans_choice('{0}Nothing flagged|{1}:count flagged section|[2,*]:count flagged sections', $this->flaggedSections->count(), ['count' => $this->flaggedSections->count()]) }}
                            </flux:heading>

                            @if ($this->flaggedSections->isEmpty())
                                <flux:callout variant="success" icon="check-circle" :heading="__('This route looks fine')">
                                    {{ __('No stretch of this route passed your risk threshold.') }}
                                </flux:callout>
                            @else
                                <div class="flex flex-col gap-2" data-test="flagged-list">
                                    @foreach ($this->flaggedSections as $section)
                                        <livewire:pages::routes.flagged-section :section="$section" :wire:key="'section-'.$section->id" />
                                    @endforeach
                                </div>
                            @endif
                        </div>

                --}}
                        {{-- The rest of the ride, so the flags can be read against what is not flagged. --}}
                {{--
                        @if ($this->unflaggedSections->isNotEmpty())
                            <div class="flex flex-col gap-3">
                                <flux:heading size="lg">
                                    {{ trans_choice('{1}:count unflagged section|[2,*]:count unflagged sections', $this->unflaggedSections->count(), ['count' => $this->unflaggedSections->count()]) }}
                                </flux:heading>

                                <div class="flex flex-col gap-2" data-test="unflagged-list">
                                    @foreach ($this->unflaggedSections as $section)
                                        <livewire:pages::routes.flagged-section :section="$section" :wire:key="'section-'.$section->id" />
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                --}}
                        <div class="flex flex-col gap-3" data-test="traffic-panel">
                            <flux:heading size="lg">
                                {{ trans_choice('{1}:count road|[2,*]:count total roads', $this->rankedTrafficSections->count(), ['count' => $this->rankedTrafficSections->count()]) }}
                            </flux:heading>

                            {{-- Held at the top of the column: the list runs
                                 longer than the column is tall, and a rider who
                                 has scrolled into the middle of a route should
                                 not have to scroll back out of it to reorder
                                 what they are reading. The background is the
                                 page's own, so the cards pass behind it rather
                                 than through it. --}}
                            <div
                                class="sticky top-0 z-10 bg-white pb-1 dark:bg-zinc-800"
                                data-scroll-panel-header
                                data-test="traffic-sort-sticky"
                            >
                                <flux:select
                                    wire:model.live="trafficSort"
                                    size="sm"
                                    :label="__('Order by')"
                                    data-test="traffic-sort"
                                >
                                    @foreach ($this->trafficSortOptions as $value => $label)
                                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>

                            @if ($this->rankedTrafficSections->isEmpty())
                                <flux:text class="text-sm" data-test="traffic-list-empty">
                                    {{ __('Nothing to list: no road on this route has a published traffic count or a reported crash beside it.') }}
                                </flux:text>
                            @else
                                <div class="flex flex-col gap-2" data-test="traffic-list">
                                    @foreach ($this->rankedTrafficSections as $section)
                                        <x-traffic-section
                                            :section="$section"
                                            :crashes="$this->trafficCrashes[$section->id] ?? []"
                                            :wire:key="'traffic-'.$section->id"
                                        />
                                    @endforeach
                                </div>
                            @endif
                        </div>
                {{-- TEMP: danger scoring is hidden until it is integrated back in. @endif --}}
                    </div>
                @endif
            </div>
        @endif
    </div>
