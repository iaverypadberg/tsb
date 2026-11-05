@php
    /**
     * The three things a rider does with a new route, in the order they do them.
     *
     * @var list<array{title: string, body: string}>
     */
    $howItWorks = [
        [
            'title' => __('Build & Export'),
            'body' => __('Plan the ride wherever you normally plan it(RWGPS, Strava etc..), then export the route as a GPX file.'),
        ],
        [
            'title' => __('Upload'),
            'body' => __("Once the route is uploaded, you'll be able to view the different traffic conditions along the route."),
        ],
    ];

    /**
     * The roadmap, earliest at the top.
     *
     * This list is the whole timeline: add, reorder or reword an entry and the
     * markup below redraws itself. Each one takes a status, a title and a line
     * about it, where `status` is one of:
     *
     *   'done'    — built, and in the app now
     *   'current' — being worked on
     *   'next'    — planned, not started
     *
     * `when` is free text — 'Shipped', 'Spring', 'Someday' — and may be left
     * out entirely, in which case no badge is drawn.
     *
     * @var list<array{status: string, when: string|null, title: string, body: string}>
     */
    $roadmap = [
        [
            'status' => 'done',
            'when' => __('Shipped'),
            'title' => __('Traffic on an uploaded route'),
            'body' => __('A GPX upload is matched to the road network and every stretch coloured by its measured traffic count, with the roads the route runs on listed in the order it rides them and rankable by the busiest or the worst reported.'),
        ],
        [
            'status' => 'done',
            'when' => __('Shipped'),
            'title' => __('Crash history beside each road'),
            'body' => __('What has actually been reported along the route: how much, how many were hurt, and whether anyone was on a bicycle or on foot.'),
        ],
        [
            'status' => 'done',
            'when' => __('Shipped'),
            'title' => __('Flag a road yourself'),
            'body' => __('Pick a road off the map, or trim the selection down to the part that is wrong, and say what is wrong with it.'),
        ],
        [
            'status' => 'current',
            'when' => __('In progress'),
            'title' => __('Danger scoring'),
            'body' => __('Scoring each road out of 100 from its tags, its traffic and its crash record, then flagging the stretches of a route that pass your own risk threshold.'),
        ],
        [
            'status' => 'next',
            'when' => null,
            'title' => __('Your next step here'),
            'body' => __('This entry is a placeholder: edit the $roadmap list at the top of resources/views/dashboard.blade.php and this timeline follows.'),
        ],
    ];

    /**
     * How each status is drawn: the dot on the line, and the badge beside the
     * title. Colour is never the only signal — the badge says it in words and
     * a planned step is hollow where a finished one is filled.
     *
     * @var array<string, array{dot: string, badge: string}>
     */
    $statusStyles = [
        'done' => [
            'dot' => 'bg-emerald-500 text-white',
            'badge' => 'emerald',
        ],
        'current' => [
            'dot' => 'bg-blue-500 text-white ring-4 ring-blue-500/20',
            'badge' => 'blue',
        ],
        'next' => [
            'dot' => 'border-2 border-neutral-300 bg-white dark:border-neutral-600 dark:bg-zinc-800',
            'badge' => 'zinc',
        ],
    ];
@endphp

<x-layouts::app :title="__('Home')">
    <div class="flex w-full flex-1 flex-col gap-12 pb-16">
        {{-- What the app is for, in the words of the rider who needs it. --}}
        <section class="flex flex-col gap-4" data-test="intro">
            <flux:heading size="2xl" level="1">{{ __("Know what you're riding into") }}</flux:heading>

            <flux:text class="max-w-2xl">
                <strong class="font-semibold">{{ __('Riding somewhere new?') }}</strong>
                {{ __('Upload the route and Traffic Screening for Bikes(TSB) shows you which of its roads carry heavy traffic before you are out there in survival mode. Flag roads you think riders should be wary of.') }}
            </flux:text>

            <div class="flex flex-wrap gap-3">
                <flux:button :href="route('routes.index')" variant="filled" icon="arrow-up-tray" wire:navigate>
                    {{ __('Upload a route') }}
                </flux:button>

                <flux:button :href="route('flags.index')" variant="filled" icon="flag" wire:navigate>
                    {{ __('Flag a road') }}
                </flux:button>
            </div>
        </section>

        {{-- <section class="flex flex-col gap-4" data-test="how-it-works">
            <flux:heading size="lg" level="2">{{ __('How to use it') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-1">
                @foreach ($howItWorks as $index => $step)
                    <div class="flex flex-col gap-2 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                        
                        <flux:heading level="3">{{ $step['title'] }}</flux:heading>
                        <flux:text>{{ $step['body'] }}</flux:text>
                        <span
                            class="flex size-8 items-center justify-center rounded-lg bg-neutral-100 text-sm font-semibold tabular-nums dark:bg-zinc-700"
                            aria-hidden="true"
                        >{{ $index + 1 }}</span>
                    </div>
                @endforeach
            </div>
        </section> --}}

        <flux:separator />

        {{-- The roadmap as a line to be ridden: the finished stretches behind,
             the one being built now, and the planned ones ahead. --}}
        <section class="flex flex-col gap-6" data-test="roadmap">
            <div class="flex flex-col gap-1">
                <flux:heading size="lg" level="2">{{ __('Where this is going') }}</flux:heading>
                <flux:text>{{ __('What is built, what is being built, and what comes after it.') }}</flux:text>
            </div>

            {{-- role="list" because Tailwind's reset strips the list styling,
                 and a list without it is not announced as one by VoiceOver. --}}
            <ol
                role="list"
                class="relative flex flex-col gap-8 border-s border-neutral-200 ps-8 dark:border-neutral-700"
            >
                @foreach ($roadmap as $step)
                    @php $style = $statusStyles[$step['status']] ?? $statusStyles['next']; @endphp

                    <li class="relative" data-test="roadmap-step">
                        {{-- Sits on the line rather than beside it, so the eye
                             reads one thread rather than two columns. --}}
                        <span
                            class="absolute -start-8 top-1 flex size-5 -translate-x-1/2 items-center justify-center rounded-full {{ $style['dot'] }}"
                            aria-hidden="true"
                        >
                            @if ($step['status'] === 'done')
                                <flux:icon.check class="size-3" />
                            @endif
                        </span>

                        <div class="flex flex-col gap-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:heading level="3">{{ $step['title'] }}</flux:heading>

                                @if (filled($step['when'] ?? null))
                                    <flux:badge :color="$style['badge']" size="sm">{{ $step['when'] }}</flux:badge>
                                @endif
                            </div>

                            <flux:text class="max-w-2xl">{{ $step['body'] }}</flux:text>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        <flux:separator />

        <section class="flex flex-col gap-4" data-test="contact">
            <div class="flex flex-col gap-1">
                <flux:heading size="lg" level="2">{{ __('Get in touch') }}</flux:heading>
                <flux:text class="max-w-2xl">
                    {{ __('All feedback & ideas welcome!') }}
                </flux:text>
            </div>

            <flux:button
                href="mailto:iaverypadberg@gmail.com"
                variant="ghost"
                icon="envelope"
                class="self-start"
            >
                iaverypadberg@gmail.com
            </flux:button>
        </section>
    </div>
</x-layouts::app>
