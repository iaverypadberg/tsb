@props(['section', 'crashes' => []])

@php
    $total = (int) ($crashes['total'] ?? 0);
    $fatal = (int) ($crashes['fatal'] ?? 0);
    $injury = (int) ($crashes['injury'] ?? 0);
    $bicycle = (int) ($crashes['bicycle'] ?? 0);
    $pedestrian = (int) ($crashes['pedestrian'] ?? 0);

    // Written once and bound three times, so the pointer and the keyboard
    // cannot drift apart.
    $showOnMap = "\$store.badroads.show('focusedTraffic', {$section->id})";
@endphp

{{--
    One road the route runs on: how busy it is, how far the route runs on it,
    and what has been reported beside it.

    A band on its own is one person's word for a road. The count is what a
    rider can weigh against their own experience of it, and the crashes are
    the question a count raises and cannot answer.

    The whole card is the control: a rider reading down the list is picking a
    road, not hunting for a pin, and the card is a much bigger target than the
    icon was — which is what matters on a phone.

    A div rather than a <button>, because a button may only contain phrasing
    content and this one holds a heading. That makes the role, the tab stop
    and the keyboard handling this element's job rather than the browser's.

    Hover and focus are a background rather than a ring, because the ring is
    already spoken for: `reveal()` puts one on the card the map asked for, and
    two rings would be two different things wearing the same clothes.
--}}
<div
    {{ $attributes->merge(['class' => 'flex cursor-pointer flex-col gap-1.5 rounded-lg border border-neutral-200 px-3 py-2 scroll-mt-20 transition-colors hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent dark:border-neutral-700 dark:hover:bg-neutral-800/60']) }}
    {{-- What the map scrolls to when the rider clicks this road on it. --}}
    data-traffic-section="{{ $section->id }}"
    data-test="traffic-section"
    role="button"
    tabindex="0"
    {{-- Named rather than "Show on map", so that tabbing through the list
         reads as the roads it is a list of. --}}
    aria-label="{{ __('Show :road on map', ['road' => $section->roadLabel() ?: __('this unnamed road')]) }}"
    x-on:click="{{ $showOnMap }}"
    x-on:keydown.enter="{{ $showOnMap }}"
    {{-- .prevent: space scrolls the list otherwise, which undoes the
         scrolling the click was asking for. --}}
    x-on:keydown.space.prevent="{{ $showOnMap }}"
>
    {{-- Wrapping, because this row holds a road name and two badges and the
         column it sits in is narrow on a laptop and narrower on a phone. The
         band badge is the longest thing on the card, and in one unwrapping
         line it was the thing that overflowed and got clipped by the list's
         own scrolling. A wrapped row only makes the card taller. --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        {{-- min-w-0 rather than shrink-0: a long road name should fall onto a
             second line, which it cannot do while it is forbidden to shrink
             below the width of the whole name. --}}
        <flux:heading size="sm" class="min-w-0">
            {{ $section->roadLabel() ?: __('Unnamed road') }}
        </flux:heading>
        


        {{-- whitespace-normal overrides the nowrap Flux puts on every badge.
             Wrapping the row keeps this badge whole at most widths; letting
             its own text break is what keeps it readable at the widths where
             the badge alone is wider than the card. --}}
        <flux:badge class="text-sm tabular-nums whitespace-normal" :color="$section->band->badgeColor()">
            {{ __(':label -', [
                "label" =>$section->band->label()
            ])}}
            @if ($section->aadt === null)
                {{ __(':km km', ['km' => number_format($section->lengthKm(), 1)]) }}
            @else
                {{ __(':count cars/day - :km km', [
                    'count' => number_format($section->aadt),
                    'km' => number_format($section->lengthKm(), 1),
                ]) }}
            @endif
        </flux:badge>

                {{-- How fast the traffic is allowed to go, which is the other half of
             the question the count answers: 400 vehicles a day on a lane and
             400 on a state highway are not the same road to ride.

             Read straight after the road's name, because it is the figure
             that says what the name means: "US 2" tells a rider nothing that
             "US 2 · 55 mph" does not tell them better.

             Neutral rather than banded, and only where the road is tagged.
             About one Vermont way in twenty carries a limit, so an absent
             badge is the ordinary case and means untagged, not slow. --}}
        @if ($section->speedLabel() !== null)
            <flux:badge color="zinc" class="shrink-0 text-sm tabular-nums" data-test="traffic-speed-limit">
                {{ $section->speedLabel() }}
            </flux:badge>
        @endif

    </div>

    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">

        {{-- Only what was actually reported. A stretch with nothing against
             it says nothing, rather than claiming a clean record: most roads
             here are absent from the crash export rather than proven safe. --}}
        {{-- Shown where there is one, but no louder than the rest: 187 of
             the 32,000 reported crashes here were fatal, and a solid red
             badge made the rarest thing on the card the first thing read. --}}

        @if ($total > 0)
            <flux:badge color="amber" size="sm" data-test="traffic-crash-total">
                {{ trans_choice('{1}:count crash reported|[2,*]:count crashes reported', $total, ['count' => $total]) }}
            </flux:badge>
        @endif

        @if ($fatal > 0)
            <flux:badge color="red" size="sm" data-test="traffic-fatal">
                {{ trans_choice('{1}:count fatal|[2,*]:count fatal', $fatal, ['count' => $fatal]) }}
            </flux:badge>
        @endif

        @if ($injury > 0)
            <flux:badge color="amber" size="sm" data-test="traffic-injury">
                {{ trans_choice('{1}:count injury|[2,*]:count injuries', $injury, ['count' => $injury]) }}
            </flux:badge>
        @endif

        @if ($bicycle > 0)
            <flux:badge color="orange" size="sm" data-test="traffic-bicycle">
                {{ trans_choice('{1}:count bicycle|[2,*]:count bicycles', $bicycle, ['count' => $bicycle]) }}
            </flux:badge>
        @endif

        @if ($pedestrian > 0)
            <flux:badge color="orange" size="sm" data-test="traffic-pedestrian">
                {{ trans_choice('{1}:count pedestrian|[2,*]:count pedestrian', $pedestrian, ['count' => $pedestrian]) }}
            </flux:badge>
        @endif

    </div>
</div>
