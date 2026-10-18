<?php

use App\Models\Route;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Routes')] class extends Component {
    use WithPagination;

    /**
     * Show the newest upload at the top of the first page.
     */
    #[On('route-uploaded')]
    public function onRouteUploaded(): void
    {
        $this->resetPage();

        unset($this->routes);
    }

    /**
     * @return LengthAwarePaginator<int, Route>
     */
    #[Computed]
    public function routes(): LengthAwarePaginator
    {
        return Route::query()
            ->where('user_id', Auth::id())
            ->latest()
            ->paginate(10);
    }

    /**
     * Delete one of the current user's routes.
     */
    public function delete(Route $route): void
    {
        $this->authorize('delete', $route);

        $route->delete();

        unset($this->routes);

        Flux::toast(variant: 'success', text: __('Route deleted.'));
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Routes') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Upload a GPX file to see it on the map.') }}</flux:text>
    </div>

    <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
        <livewire:pages::routes.upload />
    </div>

    @if ($this->routes->isEmpty())
        <div class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-neutral-300 p-12 text-center dark:border-neutral-700">
            <flux:heading size="lg">{{ __('No routes yet') }}</flux:heading>
            <flux:text>{{ __('Upload your first GPX file to get started.') }}</flux:text>
        </div>
    @else
        {{-- Phones get a stacked list; a five column table is unreadable at 400px. --}}
        <div class="grid gap-3 md:hidden">
            @foreach ($this->routes as $route)
                <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700" wire:key="route-card-{{ $route->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <flux:link :href="route('routes.show', $route)" wire:navigate class="font-medium">
                            {{ $route->name }}
                        </flux:link>

                        <flux:button
                            size="sm"
                            variant="subtle"
                            icon="trash"
                            wire:click="delete({{ $route->id }})"
                            wire:confirm="{{ __('Delete this route?') }}"
                        >
                            <span class="sr-only">{{ __('Delete') }}</span>
                        </flux:button>
                    </div>

                    <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-neutral-500 dark:text-neutral-400">
                        <div class="flex gap-1">
                            <dt>{{ __('Distance') }}</dt>
                            <dd class="tabular-nums text-neutral-900 dark:text-neutral-100">{{ number_format($route->distanceKm(), 1) }} km</dd>
                        </div>
                        <div class="flex gap-1">
                            <dt>{{ __('Climbing') }}</dt>
                            <dd class="tabular-nums text-neutral-900 dark:text-neutral-100">{{ number_format($route->elevation_gain_m) }} m</dd>
                        </div>
                        <div class="flex gap-1">
                            <dt class="sr-only">{{ __('Uploaded') }}</dt>
                            <dd>{{ $route->created_at->diffForHumans() }}</dd>
                        </div>
                    </dl>
                </div>
            @endforeach
        </div>

        <div class="hidden overflow-x-auto rounded-xl border border-neutral-200 md:block dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">{{ __('Name') }}</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">{{ __('Distance') }}</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">{{ __('Climbing') }}</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">{{ __('Uploaded') }}</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach ($this->routes as $route)
                        <tr wire:key="route-{{ $route->id }}">
                            <td class="px-4 py-3">
                                <flux:link :href="route('routes.show', $route)" wire:navigate>
                                    {{ $route->name }}
                                </flux:link>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ number_format($route->distanceKm(), 1) }} km
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ number_format($route->elevation_gain_m) }} m
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                {{ $route->created_at->diffForHumans() }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:button
                                    size="sm"
                                    variant="subtle"
                                    icon="trash"
                                    wire:click="delete({{ $route->id }})"
                                    wire:confirm="{{ __('Delete this route?') }}"
                                    :data-test="'delete-route-'.$route->id"
                                >
                                    <span class="sr-only">{{ __('Delete') }}</span>
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $this->routes->links() }}
    @endif
</div>
