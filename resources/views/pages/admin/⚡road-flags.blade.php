<?php

use App\Actions\RoadFlags\FlagGeometry;
use App\Actions\RoadFlags\ReviewRoadFlag;
use App\Models\RoadFlag;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Road flags')] class extends Component {
    use WithPagination;

    /**
     * Which pile to look at. Pending is the work; the rest is the record.
     */
    #[Url]
    public string $status = RoadFlag::STATUS_PENDING;

    /**
     * What the maintainer wants to say back, keyed by flag.
     *
     * @var array<int, string>
     */
    public array $reviewNotes = [];

    public function mount(): void
    {
        $this->authorize('review', RoadFlag::class);
    }

    /**
     * Accept a flag: it starts counting towards the road's danger score.
     */
    public function accept(RoadFlag $flag, ReviewRoadFlag $review): void
    {
        $this->authorize('review', RoadFlag::class);

        $review->accept($flag, Auth::user(), $this->noteFor($flag));

        $this->finish($flag, __('Flag accepted. The roads it covers have been rescored.'));
    }

    /**
     * Reject a flag: nothing it says reaches a score.
     */
    public function reject(RoadFlag $flag, ReviewRoadFlag $review): void
    {
        $this->authorize('review', RoadFlag::class);

        $review->reject($flag, Auth::user(), $this->noteFor($flag));

        $this->finish($flag, __('Flag rejected.'));
    }

    /**
     * Draw one flag on the map at the top of the queue.
     */
    public function showFlag(RoadFlag $flag, FlagGeometry $flagGeometry): void
    {
        $this->authorize('review', RoadFlag::class);

        $this->dispatch('flag-selection', selection: $flagGeometry->handle($flag), trim: null, fit: true);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();

        unset($this->flags);
    }

    /**
     * @return LengthAwarePaginator<int, RoadFlag>
     */
    #[Computed(persist: false)]
    public function flags(): LengthAwarePaginator
    {
        return RoadFlag::query()
            ->with(['user', 'reviewedBy'])
            ->withCount('segments')
            ->when(
                in_array($this->status, [RoadFlag::STATUS_PENDING, RoadFlag::STATUS_ACCEPTED, RoadFlag::STATUS_REJECTED], true),
                fn ($query) => $query->where('status', $this->status),
            )
            ->orderBy('status')
            ->latest('id')
            ->paginate(10);
    }

    /**
     * How many flags are waiting, which is the only number that is work.
     */
    #[Computed(persist: false)]
    public function pendingCount(): int
    {
        return RoadFlag::query()->where('status', RoadFlag::STATUS_PENDING)->count();
    }

    /**
     * The first flag on the page, so the map opens on something.
     *
     * @return array{label: string, length_m: int, parts: list<array{id: int, coordinates: list<array{float, float}>}>}|null
     */
    #[Computed(persist: false)]
    public function initialGeometry(): ?array
    {
        $first = $this->flags->first();

        return $first === null ? null : app(FlagGeometry::class)->handle($first);
    }

    /**
     * The roads a flag covers, named the way a rider would name them.
     *
     * @return string
     */
    public function roadsCovered(RoadFlag $flag): string
    {
        $names = $flag->roadSegments()
            ->limit(8)
            ->get(['road_segments.id', 'road_segments.ref', 'road_segments.name', 'road_segments.highway_type'])
            ->map(fn ($segment): string => $segment->roadKey())
            ->unique()
            ->take(4)
            ->implode(' · ');

        return $names === '' ? __('Unnamed road') : $names;
    }

    private function noteFor(RoadFlag $flag): ?string
    {
        $note = trim($this->reviewNotes[$flag->id] ?? '');

        return $note === '' ? null : $note;
    }

    private function finish(RoadFlag $flag, string $message): void
    {
        unset($this->reviewNotes[$flag->id]);
        unset($this->flags, $this->pendingCount, $this->initialGeometry);

        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Road flags') }}</flux:heading>
            <flux:text class="mt-1">
                {{ trans_choice(
                    '{0}Nothing is waiting to be reviewed.|{1}:count flag is waiting to be reviewed.|[2,*]:count flags are waiting to be reviewed.',
                    $this->pendingCount,
                    ['count' => $this->pendingCount],
                ) }}
            </flux:text>
        </div>

        <flux:select wire:model.live="status" class="max-w-xs" :label="__('Showing')">
            <flux:select.option value="pending">{{ __('Waiting') }}</flux:select.option>
            <flux:select.option value="accepted">{{ __('Accepted') }}</flux:select.option>
            <flux:select.option value="rejected">{{ __('Rejected') }}</flux:select.option>
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
        </flux:select>
    </div>

    <div
        wire:ignore
        x-data="flagMap(@js($this->initialGeometry))"
        class="h-[18rem] w-full overflow-hidden rounded-xl border border-neutral-200 sm:h-[26rem] dark:border-neutral-700"
        data-test="review-map"
    ></div>

    @if ($this->flags->isEmpty())
        <div class="rounded-xl border border-dashed border-neutral-300 p-12 text-center dark:border-neutral-700">
            <flux:heading size="lg">{{ __('Nothing here') }}</flux:heading>
            <flux:text data-test="no-flags">{{ __('No flags match that filter.') }}</flux:text>
        </div>
    @else
        <div class="grid gap-4">
            @foreach ($this->flags as $flag)
                <div
                    class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700"
                    wire:key="review-flag-{{ $flag->id }}"
                    data-test="review-flag"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
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

                                <flux:text class="text-sm">{{ __($flag->severityLabel()) }}</flux:text>
                            </div>

                            <flux:text class="mt-2 text-sm">
                                {{ $this->roadsCovered($flag) }} ·
                                <span class="tabular-nums">{{ number_format($flag->lengthKm(), 1) }} km</span> ·
                                {{ trans_choice('{1}:count road|[2,*]:count roads', $flag->segments_count, ['count' => $flag->segments_count]) }}
                            </flux:text>
                        </div>

                        <flux:button
                            size="sm"
                            variant="subtle"
                            icon="map-pin"
                            wire:click="showFlag({{ $flag->id }})"
                            :data-test="'show-flag-'.$flag->id"
                        >
                            {{ __('Show on map') }}
                        </flux:button>
                    </div>

                    <flux:text class="mt-3 block rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-900">
                        {{ $flag->notes }}
                    </flux:text>

                    <flux:text class="mt-2 text-xs">
                        {{ __('Reported by :who, :when', [
                            'who' => $flag->user?->name ?? __('a deleted account'),
                            'when' => $flag->created_at->diffForHumans(),
                        ]) }}
                        @if ($flag->reviewed_at)
                            · {{ __('reviewed by :who :when', [
                                'who' => $flag->reviewedBy?->name ?? __('a maintainer'),
                                'when' => $flag->reviewed_at->diffForHumans(),
                            ]) }}
                        @endif
                    </flux:text>

                    @if (filled($flag->review_note))
                        <flux:text class="mt-2 text-sm italic">{{ $flag->review_note }}</flux:text>
                    @endif

                    <div class="mt-4 flex flex-wrap items-end gap-3">
                        <flux:input
                            wire:model="reviewNotes.{{ $flag->id }}"
                            :label="__('Note back to the rider')"
                            :placeholder="__('Optional')"
                            class="max-w-sm"
                            :data-test="'review-note-'.$flag->id"
                        />

                        <flux:button
                            size="sm"
                            variant="primary"
                            icon="check"
                            wire:click="accept({{ $flag->id }})"
                            :data-test="'accept-flag-'.$flag->id"
                        >
                            {{ __('Accept') }}
                        </flux:button>

                        <flux:button
                            size="sm"
                            variant="subtle"
                            icon="x-mark"
                            wire:click="reject({{ $flag->id }})"
                            :data-test="'reject-flag-'.$flag->id"
                        >
                            {{ __('Reject') }}
                        </flux:button>
                    </div>
                </div>
            @endforeach
        </div>

        {{ $this->flags->links() }}
    @endif
</div>
