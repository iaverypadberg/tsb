@props(['slices'])

{{--
    One whole, divided into its parts: a single bar whose coloured lengths add
    up to it.

    A bar rather than a ring because the parts here are read against each
    other and against the whole, and a length is the one encoding people
    compare accurately without a scale. It is also the shape that fits beside
    a second one, which is the point: two bars of the same width are directly
    comparable in a way two pies are not.

    Server-rendered, deliberately: the route page re-renders itself every two
    seconds while the traffic is being worked out, and anything holding its
    own state in the browser would have to be hidden from Livewire's morph the
    way the map is. A chart with no state cannot lose any.

    The segments carry a title each, so a pointer gets the figures without the
    key; the caller lists the same figures beneath, which is what a screen
    reader is given instead of a shape.
--}}
<div
    {{ $attributes->merge([
        'class' => 'flex h-3 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800',
        'data-test' => 'stacked-bar',
    ]) }}
    aria-hidden="true"
>
    @foreach ($slices as $slice)
        {{-- A part the route has none of draws nothing. The minimum width
             below would otherwise give it a sliver, and a sliver of a band
             that is not there at all is the one thing a bar must not say. --}}
        @continue($slice['percent'] <= 0)

        {{-- The floor keeps a share too small to draw from vanishing
             altogether: a road that is half a percent of the ride is still
             one the rider is on. --}}
        <div
            style="width: {{ $slice['percent'] }}%; min-width: 2px; background-color: {{ $slice['color'] }}"
            title="{{ $slice['title'] ?? $slice['label'] }}"
            data-test="stacked-bar-slice"
        ></div>
    @endforeach
</div>
