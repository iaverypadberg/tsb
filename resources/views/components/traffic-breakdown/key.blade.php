@props(['slices', 'label', 'detailHeader' => null, 'figuresHeader' => null])

{{--
    What the colours in a bar mean, and what each one is worth.

    A table because there are now three things to read per row and a reader
    comparing bands reads down one column at a time. The headers carry the
    unit, so each row can give the figures bare.

    This is the accessible telling of the bar beside it, which is drawn
    aria-hidden: a row here says in words what a length says in pixels.
--}}
<table {{ $attributes->merge(['class' => 'w-full text-xs']) }}>
    <caption class="sr-only">{{ $label }}</caption>

    <thead>
        <tr class="text-[0.6875rem] text-neutral-400 dark:text-neutral-500">
            <td></td>

            <th scope="col" class="pl-2 pb-0.5 text-right font-normal">{{ $detailHeader }}</th>

            <th scope="col" class="pl-2 pb-0.5 text-right font-normal">{{ $figuresHeader }}</th>
        </tr>
    </thead>

    <tbody>
        @foreach ($slices as $slice)
            {{-- A band this route never touches is listed, but dimmed: it is
                 there to be found absent, not to be weighed against the ones
                 that are there. --}}
            <tr
                @class(['opacity-50' => $slice['percent'] <= 0])
                data-test="key-row"
                data-slice="{{ $slice['key'] }}"
            >
                <th scope="row" class="py-0.5 text-left font-normal">
                    <span class="flex items-center gap-1.5">
                        <span
                            class="size-2.5 shrink-0 rounded-full ring-1 ring-black/10 dark:ring-white/15"
                            style="background-color: {{ $slice['color'] }}"
                        ></span>

                        <span class="font-medium">{{ $slice['label'] }}</span>
                    </span>
                </th>

                <td class="w-px py-0.5 pl-2 text-right tabular-nums whitespace-nowrap text-neutral-500 dark:text-neutral-400">
                    {{ $slice['detail'] }}
                </td>

                <td class="w-px py-0.5 pl-2 text-right tabular-nums whitespace-nowrap">
                    {{ $slice['figures'] }}
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
