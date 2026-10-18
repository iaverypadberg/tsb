@props(['coverage' => []])

{{--
    What the danger score is built from, and what it cannot see.

    A rider who disagrees with a flag deserves to know how the number was
    arrived at, and where the data behind it is thin. Hiding the gaps would
    make the score look more authoritative than it is.
--}}
<details {{ $attributes->merge(['class' => 'rounded-xl border border-neutral-200 dark:border-neutral-700']) }}>
    <summary class="cursor-pointer list-none p-4 text-sm font-medium select-none">
        <span class="inline-flex items-center gap-2">
            <flux:icon.information-circle class="size-4" />
            {{ __('How these scores are worked out') }}
        </span>
    </summary>

    <div class="space-y-4 border-t border-neutral-200 p-4 text-sm dark:border-neutral-700">
        <div>
            <flux:heading size="sm">{{ __('What goes into a score') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Every road scores from 0 to 100. The starting point is what kind of road it is, then speed limit, lane count and a route number push it up, while a bike lane, a shoulder or an unpaved surface bring it down. Anything reported as locally bad adds to it.') }}
            </flux:text>
        </div>

        <div>
            <flux:heading size="sm">{{ __('What it cannot see') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('There is no traffic count behind this. A quiet rural highway and a busy suburban one of the same class score alike, which is the biggest single weakness.') }}
            </flux:text>

            @if (filled($coverage))
                <ul class="mt-2 list-inside list-disc space-y-1 text-neutral-500 dark:text-neutral-400">
                    <li>{{ __('Speed limits are tagged on :percent% of roads.', ['percent' => $coverage['speed']]) }}</li>
                    <li>{{ __('Surface is tagged on :percent%.', ['percent' => $coverage['surface']]) }}</li>
                    <li>{{ __('A shoulder is tagged on :percent% — too few to rely on, so an untagged road is treated as unknown rather than shoulderless.', ['percent' => $coverage['shoulder']]) }}</li>
                </ul>
            @endif
        </div>

        <div>
            <flux:heading size="sm">{{ __('Where the roads come from') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Road data is from OpenStreetMap, available under the Open Database Licence.') }}
                <flux:link href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">
                    {{ __('© OpenStreetMap contributors') }}
                </flux:link>
            </flux:text>
        </div>

        <flux:text class="text-neutral-500 dark:text-neutral-400">
            {{ __('If a flag looks wrong, it probably is. Treat this as a prompt to look at the map, not a verdict.') }}
        </flux:text>
    </div>
</details>
