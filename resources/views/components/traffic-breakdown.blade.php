@props(['breakdown'])

@php
    $bands = $breakdown['bands'];
    $uncounted = $breakdown['uncounted'];

    $slices = array_map(fn (array $band): array => [
        'key' => $band['band'],
        'label' => $band['label'],
        'percent' => $band['percent'],
        'color' => $band['color'],
        'detail' => $band['shortRange'],
        'figures' => $band['km'] > 0
            ? sprintf('%s km · %s%%', number_format($band['km'], 1), $band['percent'])
            : __('none'),
        'title' => sprintf(
            '%s (%s) — %s km, %s%% of the route',
            $band['label'],
            $band['range'],
            number_format($band['km'], 1),
            $band['percent'],
        ),
    ], $bands);
@endphp

{{--
    What this ride is made of: how far it runs on each sort of road.

    One bar, because the parts are one whole — the ride — and the question is
    what proportion of it is spent in traffic. A length is the encoding people
    compare accurately without a scale.

    Roads with no published count are drawn as quiet, which is what they are
    in practice here. The line beneath says how much of the bar rests on that
    assumption, so the reader can discount it if their route is mostly dirt.
--}}
<div
    {{ $attributes->merge(['class' => 'flex flex-col gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700']) }}
    data-test="traffic-breakdown"
>
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <flux:heading level="2">{{ __('Traffic breakdown along route') }}</flux:heading>

        <flux:text class="text-xs">
            {{ trans_choice(
                '{1}:count road along :km km|[2,*]:count roads along :km km',
                $breakdown['roads'],
                ['count' => $breakdown['roads'], 'km' => number_format($breakdown['km'], 1)],
            ) }}
        </flux:text>
    </div>

    @if ($bands === [])
        <flux:text class="text-sm" data-test="traffic-breakdown-empty">
            {{ __('No stretch of this route could be matched to a road, so there is nothing to break down.') }}
        </flux:text>
    @else
        <x-stacked-bar :slices="$slices" data-test="band-chart" />

        <x-traffic-breakdown.key
            :slices="$slices"
            :label="__('Kilometres of this route by how busy the road is')"
            :detail-header="__('cars/day')"
            :figures-header="__('of route')"
            data-test="band-key"
        />

        @if ($uncounted['km'] > 0)
            {{-- The assumption, stated where the bar it holds up can be seen.
                 A reader who disagrees with it can subtract it. --}}
            <flux:text class="text-xs" data-test="uncounted-note">
                {{ trans_choice(
                    '{1}*Includes :km km on :count road with no published count, read as quiet.|[2,*]*Includes :km km with no ADT, read as quiet.',
                    $uncounted['roads'],
                    ['count' => $uncounted['roads'], 'km' => number_format($uncounted['km'], 1)],
                ) }}
            </flux:text>
        @endif
    @endif
</div>
