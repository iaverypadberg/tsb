<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Home') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="map" :href="route('routes.index')" :current="request()->routeIs('routes.*')" wire:navigate>
                        {{ __('Routes') }}
                    </flux:sidebar.item>

                    {{-- Flagging a road is for riders with an account, so a guest
                         follows this to the sign-up rather than to a login wall. --}}
                    @auth
                        <flux:sidebar.item icon="flag" :href="route('flags.index')" :current="request()->routeIs('flags.*')" wire:navigate>
                            {{ __('Flag a road') }}
                        </flux:sidebar.item>
                    @else
                        <flux:sidebar.item icon="flag" :href="route('register')">
                            {{ __('Flag a road') }}
                        </flux:sidebar.item>
                    @endauth

                    @can('review', \App\Models\RoadFlag::class)
                        <flux:sidebar.item icon="inbox" :href="route('admin.road-flags')" :current="request()->routeIs('admin.*')" wire:navigate>
                            {{ __('Review flags') }}
                        </flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            @auth
                <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
            @else
                <flux:sidebar.nav class="hidden lg:block">
                    <flux:sidebar.item icon="user-plus" :href="route('register')">
                        {{ __('Create an account') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="arrow-right-end-on-rectangle" :href="route('login')">
                        {{ __('Log in') }}
                    </flux:sidebar.item>
                </flux:sidebar.nav>
            @endauth
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            @guest
                <flux:button :href="route('login')" variant="subtle" size="sm">
                    {{ __('Log in') }}
                </flux:button>

                <flux:button :href="route('register')" variant="primary" size="sm" class="ms-2">
                    {{ __('Create an account') }}
                </flux:button>
            @endguest

            @auth
            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
            @endauth
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
