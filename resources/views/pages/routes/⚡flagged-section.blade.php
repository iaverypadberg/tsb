<?php

use App\Models\FlaggedSection;
use Livewire\Component;

new class extends Component {
    public FlaggedSection $section;

    /**
     * How alarming the section should look, by how bad the road is.
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

<div
    class="flex items-center gap-3 rounded-lg border border-neutral-200 px-3 py-2 dark:border-neutral-700"
    data-test="flagged-section"
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
            x-on:click="$store.badroads.focused = {{ $section->id }}"
            :data-test="'focus-section-'.$section->id"
        />
    </flux:tooltip>
</div>
