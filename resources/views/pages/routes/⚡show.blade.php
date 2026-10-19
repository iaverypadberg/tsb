<?php

use App\Jobs\AnalyseRoute;
use App\Models\FlaggedSection;
use App\Models\Route;
use App\Models\RouteAnalysis;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Route $route;

    /**
     * How many analyses one rider may start per minute.
     */
    public const ANALYSES_PER_MINUTE = 5;

    /**
     * How much traffic the rider is willing to put up with.
     */
    public string $riskTolerance = RouteAnalysis::TOLERANCE_NORMAL;

    public function mount(Route $route): void
    {
        $this->authorize('view', $route);

        $this->route = $route;
        $this->riskTolerance = $this->analysis?->risk_tolerance
            ?? Auth::user()->risk_tolerance
            ?: RouteAnalysis::TOLERANCE_NORMAL;
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
    }

    /**
     * Queue a fresh look at this route.
     */
    public function analyse(): void
    {
        $this->authorize('view', $this->route);

        $this->validate([
            'riskTolerance' => ['required', 'in:'.implode(',', array_keys(RouteAnalysis::THRESHOLDS))],
        ]);

        // Each run is a couple of seconds of spatial work against every road
        // in the region, so it is worth holding someone to a sane pace.
        if (RateLimiter::tooManyAttempts($this->rateLimitKey(), self::ANALYSES_PER_MINUTE)) {
            $this->addError('riskTolerance', __('That is a lot of analysing. Try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($this->rateLimitKey()),
            ]));

            return;
        }

        RateLimiter::increment($this->rateLimitKey());

        $analysis = $this->route->analyses()->create([
            'status' => RouteAnalysis::STATUS_PENDING,
            'risk_tolerance' => $this->riskTolerance,
        ]);

        AnalyseRoute::dispatch($analysis);

        unset($this->analysis, $this->flaggedSections);
    }

    /**
     * Rate limiting is per rider, not per route: the cost is the same
     * whichever route is being analysed.
     */
    private function rateLimitKey(): string
    {
        return 'analyse-route:'.Auth::id();
    }

    /**
     * How much of the road data carries each tag, as whole percentages.
     *
     * Cached: it changes only when an extract is imported, and the honesty
     * of the explanation is worth a query an hour.
     *
     * @return array<string, int>
     */
    #[Computed(persist: false)]
    public function tagCoverage(): array
    {
        return Cache::remember('road-segments.tag-coverage', now()->addHour(), function (): array {
            $row = DB::selectOne(
                'select
                    count(*) as total,
                    count(speed_limit_kmh) as speed,
                    count(surface) as surface,
                    count(has_shoulder) as shoulder
                 from road_segments',
            );

            if ($row === null || (int) $row->total === 0) {
                return [];
            }

            $share = fn (int $count): int => (int) round(100 * $count / (int) $row->total);

            return [
                'speed' => $share((int) $row->speed),
                'surface' => $share((int) $row->surface),
                'shoulder' => $share((int) $row->shoulder),
            ];
        });
    }

    /**
     * The score at which the chosen setting starts flagging a road.
     */
    #[Computed(persist: false)]
    public function threshold(): int
    {
        return RouteAnalysis::THRESHOLDS[$this->riskTolerance]
            ?? RouteAnalysis::THRESHOLDS[RouteAnalysis::TOLERANCE_NORMAL];
    }

    /**
     * The most recent analysis, if the route has ever been looked at.
     */
    #[Computed(persist: false)]
    public function analysis(): ?RouteAnalysis
    {
        return $this->route->analyses()->latest('id')->first();
    }

    /**
     * @return Collection<int, FlaggedSection>
     */
    #[Computed(persist: false)]
    public function flaggedSections(): Collection
    {
        if ($this->analysis?->status !== RouteAnalysis::STATUS_COMPLETED) {
            return collect();
        }

        return $this->analysis->flaggedSections()->orderBy('start_fraction')->get();
    }

    /**
     * Whether the page should keep checking for the job to finish.
     */
    #[Computed(persist: false)]
    public function isWorking(): bool
    {
        return $this->analysis !== null && ! $this->analysis->isFinished();
    }

    /**
     * Whether there are results worth showing beside the map.
     */
    #[Computed(persist: false)]
    public function isComplete(): bool
    {
        return $this->analysis?->status === RouteAnalysis::STATUS_COMPLETED;
    }

    /**
     * @return list<array{float, float}>
     */
    #[Computed(persist: false)]
    public function coordinates(): array
    {
        return $this->route->coordinatesForMap();
    }

    /**
     * Flagged stretches, shaped for the map.
     *
     * @return list<array{id: int, danger: int, label: string, coordinates: list<array{float, float}>}>
     */
    #[Computed(persist: false)]
    public function flaggedForMap(): array
    {
        return $this->flaggedSections
            ->map(fn (FlaggedSection $section): array => [
                'id' => $section->id,
                'danger' => $section->max_danger,
                'label' => implode(' · ', $section->reasons ?? []),
                'coordinates' => $section->coordinatesForMap(),
            ])
            ->all();
    }

    /**
     * How much of the route the rider was warned about.
     */
    #[Computed(persist: false)]
    public function flaggedShare(): int
    {
        $flagged = (int) ($this->analysis?->summary['flagged_m'] ?? 0);

        return $this->route->distance_m > 0
            ? (int) round(100 * $flagged / $this->route->distance_m)
            : 0;
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 pb-24 sm:pb-40" @if ($this->isWorking) wire:poll.2s @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $route->name }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Uploaded :when', ['when' => $route->created_at->diffForHumans()]) }}
            </flux:text>
        </div>

        <flux:button :href="route('routes.index')" variant="subtle" icon="arrow-left" wire:navigate>
            {{ __('All routes') }}
        </flux:button>
    </div>

    <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
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
    </dl>

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

    <div class="grid gap-4 @if ($this->isComplete) lg:grid-cols-3 @endif" data-test="map-layout">
        <div
            wire:ignore.self
            x-data="routeMap(@js($this->coordinates), @js($this->flaggedForMap))"
            wire:key="map-{{ $this->analysis?->id }}-{{ $this->flaggedSections->count() }}"
            class="h-[24rem] w-full overflow-hidden rounded-xl border border-neutral-200 sm:h-[38rem] lg:col-span-2 dark:border-neutral-700"
            data-test="route-map"
        ></div>

        @if ($this->isComplete)
            <div class="flex min-h-0 flex-col gap-3 lg:h-[38rem]">
                <flux:heading size="lg">
                    {{ trans_choice('{0}Nothing flagged|{1}:count flagged section|[2,*]:count flagged sections', $this->flaggedSections->count(), ['count' => $this->flaggedSections->count()]) }}
                </flux:heading>

                @if ($this->flaggedSections->isEmpty())
                    <flux:callout variant="success" icon="check-circle" :heading="__('This route looks fine')">
                        {{ __('No stretch of this route passed your risk threshold.') }}
                    </flux:callout>
                @else
                    <div class="flex min-h-0 flex-col gap-2 overflow-y-auto pr-1" data-test="flagged-list">
                        @foreach ($this->flaggedSections as $section)
                            <livewire:pages::routes.flagged-section :section="$section" :wire:key="'section-'.$section->id" />
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
