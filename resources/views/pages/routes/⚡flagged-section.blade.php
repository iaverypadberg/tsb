<?php

use App\Models\FlaggedSection;
use Livewire\Component;

new class extends Component {
    public FlaggedSection $section;

    /**
     * How alarming the section should look, by how bad the road is.
     *
     * Read off the score rather than off whether the section was flagged, so
     * a stretch that came close to a brave rider's threshold still looks
     * like what it is in the unflagged list.
     */
    public function severity(): string
    {
        return match (true) {
            $this->section->max_danger >= 90 => 'danger',
            $this->section->max_danger >= 70 => 'warning',
            default => 'neutral',
        };
    }
}; ?>

{{-- A dashed border marks the stretches nothing was flagged on, so the two
     lists stay tellable apart when they are scrolled together. --}}
<div
    @class([
        'flex items-center gap-3 rounded-lg border px-3 py-2',
        'border-neutral-200 dark:border-neutral-700' => $section->flagged,
        'border-dashed border-neutral-200/80 dark:border-neutral-700/80' => ! $section->flagged,
    ])
    data-test="{{ $section->flagged ? 'flagged-section' : 'unflagged-section' }}"
>
    <flux:badge :variant="$this->severity()" size="sm" class="shrink-0">
        {{ __(':score/100', ['score' => $section->max_danger]) }}
    </flux:badge>

    <flux:heading size="sm" class="shrink-0">
        {{ number_format($section->lengthKm(), 1) }} km
    </flux:heading>

    @if (filled($section->reasons))
        <flux:text class="min-w-0 flex-1 truncate text-sm">
            {{ implode(' · ', $section->reasons) }}
        </flux:text>
    @endif

    <flux:tooltip :content="__('Show on map')" class="ml-auto shrink-0">
        <flux:button
            size="xs"
            variant="subtle"
            icon="map-pin"
            square
            :aria-label="__('Show on map')"
            x-on:click="$store.badroads.show('focused', {{ $section->id }})"
            :data-test="'focus-section-'.$section->id"
        />
    </flux:tooltip>
</div>
