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
    class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700"
    data-test="flagged-section"
>
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
            <flux:badge :variant="$this->severity()" size="sm">
                {{ __(':score/100', ['score' => $section->max_danger]) }}
            </flux:badge>

            <flux:heading size="sm" class="truncate">
                {{ number_format($section->lengthKm(), 1) }} km
            </flux:heading>
        </div>

        @if (filled($section->reasons))
            <flux:text class="mt-2 text-sm">
                {{ implode(' · ', $section->reasons) }}
            </flux:text>
        @endif
    </div>

    <flux:button
        size="sm"
        variant="subtle"
        icon="map-pin"
        x-on:click="$store.badroads.focused = {{ $section->id }}"
        :data-test="'focus-section-'.$section->id"
    >
        {{ __('Show on map') }}
    </flux:button>
</div>
