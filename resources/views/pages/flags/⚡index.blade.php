<?php

use App\Actions\RoadFlags\FlagGeometry;
use App\Actions\RoadFlags\RoadSegmentsInView;
use App\Actions\RoadFlags\RoadSelection;
use App\Actions\RoadFlags\SelectRoad;
use App\Actions\RoadFlags\StoreRoadFlag;
use App\Models\RoadFlag;
use App\Models\SegmentReport;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Flag a road')] class extends Component {
    /**
     * How many roads one rider may flag per minute.
     */
    public const FLAGS_PER_MINUTE = 5;

    /**
     * Enough text for an admin to judge the report by.
     */
    public const MIN_NOTES = 15;

    public const MAX_NOTES = 2000;

    /**
     * The shortest stretch of a road worth flagging, as a fraction of it.
     * Anything less is a slip of the hand rather than a stretch of road.
     */
    public const MIN_TRIM = 0.01;

    /**
     * The ways the selected road covers, whether or not it is trimmed.
     *
     * @var list<int>
     */
    public array $selectedSegmentIds = [];

    /**
     * How far along the road each end sits. A freshly clicked road runs
     * from one end to the other until an end is dragged in.
     */
    public float $trimStart = 0.0;

    public float $trimEnd = 1.0;

    /**
     * What the rider is told they have picked, kept across requests so the
     * geometry itself never has to make the round trip.
     *
     * @var array{label: string, length_m: int}|null
     */
    public ?array $selectionSummary = null;

    /**
     * Everything the rider says is wrong with the road.
     *
     * @var list<string>
     */
    public array $reasons = [];

    public int $severity = 3;

    public string $notes = '';

    /**
     * Take the ends where the rider dropped them.
     */
    public function setTrim(float $start, float $end): void
    {
        if ($this->selectedSegmentIds === []) {
            return;
        }

        $from = max(0.0, min(1.0, min($start, $end)));
        $to = max(0.0, min(1.0, max($start, $end)));

        if ($to - $from < self::MIN_TRIM) {
            Flux::toast(variant: 'warning', text: __('That is too short a stretch to flag. Drag the ends further apart.'));

            // The map moved its handles before asking; put them back where
            // the selection actually still is.
            $this->applySelection($this->currentSelection(), fit: false);

            return;
        }

        $this->trimStart = $from;
        $this->trimEnd = $to;

        $this->applySelection($this->currentSelection(), fit: false);
    }

    /**
     * The roads under the map's current view.
     *
     * `capped` says the view holds more roads than were sent, which is
     * what stops the map reusing a partial answer as it moves.
     *
     * @return array{roads: list<array{id: int, label: string, danger: int, coordinates: list<array{float, float}>}>, zoomed_out: bool, capped: bool}
     */
    #[Renderless]
    public function roadsInView(
        float $south,
        float $west,
        float $north,
        float $east,
        int $zoom,
        RoadSegmentsInView $roadsInView,
    ): array {
        if ($zoom < RoadSegmentsInView::MIN_ZOOM) {
            return ['roads' => [], 'zoomed_out' => true, 'capped' => false];
        }

        $roads = $roadsInView->handle($south, $west, $north, $east);

        return [
            'roads' => $roads,
            'zoomed_out' => false,
            'capped' => count($roads) >= RoadSegmentsInView::MAX_SEGMENTS,
        ];
    }

    /**
     * A click on the map takes the whole road it landed on. Trimming comes
     * after, by dragging the ends in.
     */
    public function selectRoad(int $roadSegmentId, SelectRoad $selectRoad): void
    {
        $selection = $selectRoad->handle($roadSegmentId);

        $this->selectedSegmentIds = $selection->roadSegmentIds();

        // A road comes back whole, however the last one was trimmed, so
        // clicking it again is how a rider starts over.
        $this->trimStart = 0.0;
        $this->trimEnd = 1.0;

        $this->applySelection($selection);
    }

    /**
     * Take one reason back out of the bank.
     */
    public function removeReason(string $reason): void
    {
        $this->reasons = array_values(array_filter(
            $this->reasons,
            fn (string $chosen): bool => $chosen !== $reason,
        ));
    }

    /**
     * Drop whatever is picked and tell the map to stop drawing it.
     */
    public function clearSelection(): void
    {
        $this->reset('selectedSegmentIds', 'trimStart', 'trimEnd', 'selectionSummary');

        $this->dispatch('flag-selection', selection: null, trim: null, fit: false);
    }

    /**
     * Submit what is picked as a flag for review.
     */
    public function submit(StoreRoadFlag $storeRoadFlag): void
    {
        $this->validate();

        $selection = $this->currentSelection();

        if ($selection->isEmpty()) {
            $this->addError('selection', __('Pick a road on the map first.'));

            return;
        }

        $key = 'flag-road:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, self::FLAGS_PER_MINUTE)) {
            $this->addError('selection', __('That is a lot of flagging. Try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($key),
            ]));

            return;
        }

        RateLimiter::increment($key);

        $storeRoadFlag->handle(
            Auth::user(),
            $selection,
            array_values($this->reasons),
            $this->severity,
            $this->notes,
        );

        $this->reset('reasons', 'notes', 'severity');
        $this->clearSelection();

        unset($this->flags);

        Flux::toast(variant: 'success', text: __('Thanks — a maintainer will take a look at that road.'));
    }

    /**
     * Draw one of the rider's own flags on the map.
     */
    public function showFlag(RoadFlag $flag, FlagGeometry $flagGeometry): void
    {
        $this->authorize('view', $flag);

        $this->dispatch('flag-selection', selection: $flagGeometry->handle($flag), trim: null, fit: true);
    }

    /**
     * Withdraw a flag nobody has acted on yet.
     */
    public function withdraw(RoadFlag $flag): void
    {
        $this->authorize('delete', $flag);

        $flag->delete();

        unset($this->flags);

        Flux::toast(variant: 'success', text: __('Flag withdrawn.'));
    }

    /**
     * The rider's own flags, newest first.
     *
     * @return Collection<int, RoadFlag>
     */
    #[Computed(persist: false)]
    public function flags(): Collection
    {
        return RoadFlag::query()
            ->where('user_id', Auth::id())
            ->withCount('segments')
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /**
     * Where to open the map: the start of the rider's most recent route if
     * they have uploaded one, since that is the area they ride.
     *
     * @return array{float, float}
     */
    #[Computed(persist: false)]
    public function mapCentre(): array
    {
        $row = DB::selectOne(
            'select ST_Y(ST_StartPoint(geom)) as lat, ST_X(ST_StartPoint(geom)) as lon
             from routes where user_id = ? order by id desc limit 1',
            [Auth::id()],
        );

        $values = (array) $row;

        return isset($values['lat'], $values['lon'])
            ? [(float) $values['lat'], (float) $values['lon']]
            : [44.26, -72.58];
    }

    /**
     * Whether the rider has picked something they could submit.
     */
    #[Computed(persist: false)]
    public function hasSelection(): bool
    {
        return $this->selectionSummary !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'reasons' => ['required', 'array', 'min:1'],
            'reasons.*' => ['string', 'in:'.implode(',', RoadFlag::REASONS)],
            'severity' => ['required', 'integer', 'between:'.SegmentReport::MIN_SEVERITY.','.SegmentReport::MAX_SEVERITY],
            'notes' => ['required', 'string', 'min:'.self::MIN_NOTES, 'max:'.self::MAX_NOTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'notes' => __('description'),
            'reasons' => __('what is wrong with it'),
        ];
    }

    /**
     * What is picked right now, rebuilt from the ways and the two ends held
     * in the component rather than carried around as geometry.
     */
    private function currentSelection(): RoadSelection
    {
        if ($this->selectedSegmentIds === []) {
            return new RoadSelection;
        }

        $selectRoad = app(SelectRoad::class);

        return $this->isTrimmed()
            ? $selectRoad->trim($this->selectedSegmentIds, $this->trimStart, $this->trimEnd)
            : $selectRoad->forSegments($this->selectedSegmentIds);
    }

    /**
     * Whether the rider has actually pulled an end in, as opposed to
     * opening the handles and leaving them where they were.
     */
    private function isTrimmed(): bool
    {
        return $this->trimStart > 0.0 || $this->trimEnd < 1.0;
    }

    /**
     * Hand a fresh selection to both the form and the map.
     *
     * The map only refits itself around a newly clicked road: refitting
     * while someone drags an end in would pull the map out from under them.
     */
    private function applySelection(RoadSelection $selection, bool $fit = true): void
    {
        if ($selection->isEmpty()) {
            $this->clearSelection();

            return;
        }

        $this->selectionSummary = [
            'label' => $selection->label(),
            'length_m' => $selection->lengthMetres(),
        ];

        $this->resetErrorBag('selection');

        $this->dispatch(
            'flag-selection',
            selection: $selection->forMap(),
            trim: [$this->trimStart, $this->trimEnd],
            fit: $fit,
        );
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Flag a dangerous road') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('Pick a road you know is bad to ride and say why. A maintainer reads every one before it changes anybody\'s route.') }}
        </flux:text>
    </div>

    <flux:text class="text-sm" data-test="map-hint">
        @if ($this->hasSelection)
            {{ __('Drag either end of the road in to pick out part of it, or click another road.') }}
        @else
            {{ __('Click a road to select all of it.') }}
        @endif
    </flux:text>

    <div
        wire:ignore
        x-data="segmentFlagger(@js($this->mapCentre), @js(\App\Actions\RoadFlags\RoadSegmentsInView::MIN_ZOOM))"
        class="h-[22rem] w-full overflow-hidden rounded-xl border border-neutral-200 sm:h-[34rem] dark:border-neutral-700"
        data-test="flag-map"
    ></div>

    @error('selection')
        <flux:callout variant="danger" icon="exclamation-triangle">{{ $message }}</flux:callout>
    @enderror

    <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
        @if ($this->hasSelection)
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3" data-test="selection-summary">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge variant="warning" size="sm">
                        {{ $trimStart > 0 || $trimEnd < 1 ? __('Part of road') : __('Whole road') }}
                    </flux:badge>
                    <flux:heading size="sm">{{ $selectionSummary['label'] }}</flux:heading>
                    <flux:text class="text-sm tabular-nums">
                        {{ number_format($selectionSummary['length_m'] / 1000, 1) }} km
                    </flux:text>
                </div>

                <flux:button size="sm" variant="subtle" icon="x-mark" wire:click="clearSelection" data-test="clear-selection">
                    {{ __('Clear') }}
                </flux:button>
            </div>
        @else
            <flux:text class="mb-4 block text-sm" data-test="nothing-selected">
                {{ __('Nothing selected yet. Pick a road on the map above.') }}
            </flux:text>
        @endif

        <form wire:submit="submit" class="grid gap-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('What is wrong with it') }}</flux:label>

                    <div
                        class="flex min-h-10 flex-wrap items-center gap-2 rounded-lg border border-neutral-200 p-2 dark:border-neutral-700"
                        data-test="reason-bank"
                    >
                        @foreach ($reasons as $chosen)
                            <flux:badge size="sm" variant="pill" color="amber" wire:key="reason-{{ $loop->index }}">
                                {{ __($chosen) }}

                                <flux:badge.close
                                    wire:click="removeReason('{{ $chosen }}')"
                                    :data-test="'remove-reason-'.$loop->index"
                                />
                            </flux:badge>
                        @endforeach

                        @if ($reasons === [])
                            <flux:text class="px-1 text-sm">{{ __('Nothing chosen yet') }}</flux:text>
                        @endif

                        <flux:dropdown position="bottom start">
                            <flux:button size="xs" variant="subtle" icon="plus" data-test="reasons-dropdown">
                                {{ __('Add') }}
                            </flux:button>

                            <flux:menu>
                                {{-- Live, so the bank fills in as the boxes are ticked. --}}
                                <flux:menu.checkbox.group wire:model.live="reasons">
                                    @foreach (\App\Models\RoadFlag::REASONS as $reasonOption)
                                        <flux:menu.checkbox :value="$reasonOption">
                                            {{ __($reasonOption) }}
                                        </flux:menu.checkbox>
                                    @endforeach
                                </flux:menu.checkbox.group>
                            </flux:menu>
                        </flux:dropdown>
                    </div>

                    <flux:error name="reasons" />
                </flux:field>

                <flux:select wire:model="severity" :label="__('How bad is it')">
                    @foreach (\App\Models\RoadFlag::SEVERITY_LABELS as $value => $label)
                        <flux:select.option :value="$value">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:textarea
                wire:model="notes"
                :label="__('Tell the maintainer what happens there')"
                :description="__('What a rider runs into, and where on the road. This is what the flag is judged on.')"
                :placeholder="__('The bridge over the river has no shoulder and the parapet forces you into the lane, with trucks coming the other way.')"
                rows="4"
                data-test="flag-notes"
            />

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary" icon="flag" :disabled="! $this->hasSelection" data-test="submit-flag">
                    {{ __('Flag this road') }}
                </flux:button>

                <flux:text wire:loading wire:target="submit" class="text-sm">{{ __('Sending…') }}</flux:text>
            </div>
        </form>
    </div>

    <div>
        <flux:heading size="lg" class="mb-3">{{ __('Roads you have flagged') }}</flux:heading>

        @if ($this->flags->isEmpty())
            <div class="rounded-xl border border-dashed border-neutral-300 p-8 text-center dark:border-neutral-700">
                <flux:text data-test="no-flags">{{ __('You have not flagged any roads yet.') }}</flux:text>
            </div>
        @else
            <div class="grid gap-3">
                @foreach ($this->flags as $flag)
                    <div
                        class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700"
                        wire:key="flag-{{ $flag->id }}"
                        data-test="rider-flag"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:badge
                                    size="sm"
                                    :variant="match ($flag->status) {
                                        \App\Models\RoadFlag::STATUS_ACCEPTED => 'success',
                                        \App\Models\RoadFlag::STATUS_REJECTED => 'danger',
                                        default => 'warning',
                                    }"
                                >
                                    {{ __(ucfirst($flag->status)) }}
                                </flux:badge>

                                <flux:heading size="sm">{{ $flag->reasonList() }}</flux:heading>

                                <flux:text class="text-sm tabular-nums">
                                    {{ number_format($flag->lengthKm(), 1) }} km ·
                                    {{ trans_choice('{1}:count road|[2,*]:count roads', $flag->segments_count, ['count' => $flag->segments_count]) }}
                                </flux:text>
                            </div>

                            <flux:text class="mt-2 text-sm">{{ $flag->notes }}</flux:text>

                            @if (filled($flag->review_note))
                                <flux:text class="mt-2 text-sm italic" data-test="review-note">
                                    {{ __('Maintainer: :note', ['note' => $flag->review_note]) }}
                                </flux:text>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            <flux:button
                                size="sm"
                                variant="subtle"
                                icon="map-pin"
                                wire:click="showFlag({{ $flag->id }})"
                                :data-test="'show-flag-'.$flag->id"
                            >
                                {{ __('Show on map') }}
                            </flux:button>

                            @if ($flag->isPending())
                                <flux:button
                                    size="sm"
                                    variant="subtle"
                                    icon="trash"
                                    wire:click="withdraw({{ $flag->id }})"
                                    wire:confirm="{{ __('Withdraw this flag?') }}"
                                    :data-test="'withdraw-flag-'.$flag->id"
                                >
                                    <span class="sr-only">{{ __('Withdraw') }}</span>
                                </flux:button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
