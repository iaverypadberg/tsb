<?php

use App\Actions\Routes\InvalidGpxFile;
use App\Actions\Routes\RouteOwner;
use App\Actions\Routes\StoreGpxRoute;
use App\Actions\Visitors\CurrentVisitor;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    /**
     * The largest GPX file we accept, in kilobytes.
     */
    public const MAX_FILE_SIZE_KB = 10240;

    /**
     * How many routes one rider may upload per minute.
     */
    public const UPLOADS_PER_MINUTE = 10;

    /**
     * How many routes one address may upload per hour without an account.
     *
     * Counted per hour rather than per minute, and by address rather than by
     * session, because an open endpoint that parses a ten megabyte file and
     * queues work off it is worth being stingy with. Enough for someone to
     * try the thing and retry a bad export; not enough to be used as free
     * batch processing.
     */
    public const GUEST_UPLOADS_PER_HOUR = 5;

    public ?TemporaryUploadedFile $gpx = null;

    /**
     * Validate and store the uploaded GPX file as a route.
     */
    public function save(StoreGpxRoute $storeGpxRoute, CurrentVisitor $visitor): void
    {
        $this->validate();

        if (! $this->isWithinUploadLimit($visitor)) {
            return;
        }

        $user = Auth::user();

        $owner = $user instanceof User
            ? RouteOwner::rider($user)
            : RouteOwner::guest($visitor->issueToken());

        try {
            $route = $storeGpxRoute->handle($owner, $this->gpx);
        } catch (InvalidGpxFile $e) {
            $this->addError('gpx', $e->getMessage());

            return;
        }

        $this->reset('gpx');

        // A guest has no route list to come back to, and the result is the
        // only reason they uploaded anything, so they are taken to it.
        if ($owner->isGuest()) {
            $this->redirectRoute('routes.show', ['route' => $route], navigate: true);

            return;
        }

        Flux::toast(variant: 'success', text: __('":name" uploaded.', ['name' => $route->name]));

        $this->dispatch('route-uploaded', routeId: $route->getRouteKey());
    }

    /**
     * Whether there is room for another upload, saying so if there is not.
     *
     * Parsing and storing a large GPX is not free, and neither is the disk it
     * lands on.
     */
    private function isWithinUploadLimit(CurrentVisitor $visitor): bool
    {
        $isGuest = ! Auth::check();

        $key = 'upload-route:'.$visitor->rateLimitKey();
        $allowance = $isGuest ? self::GUEST_UPLOADS_PER_HOUR : self::UPLOADS_PER_MINUTE;
        $decaySeconds = $isGuest ? 3600 : 60;

        if (RateLimiter::tooManyAttempts($key, $allowance)) {
            $this->addError('gpx', $isGuest
                ? __('That is a lot of uploading for one visit. Try again in :minutes minutes.', [
                    'minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60)),
                ])
                : __('That is a lot of uploading. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]));

            return false;
        }

        RateLimiter::increment($key, $decaySeconds);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'gpx' => [
                'required',
                'file',
                'max:'.self::MAX_FILE_SIZE_KB,
                'extensions:gpx,xml',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['gpx' => __('GPX file')];
    }
}; ?>

<div>
    <form wire:submit="save" class="space-y-4">
        <flux:input
            type="file"
            wire:model="gpx"
            accept=".gpx,application/gpx+xml"
            :label="__('GPX file upload')"
            {{-- :description="__('Export a route(GPX file) from your favorite route planning service, then upload it here to see how overlaps with traffic.')" --}}
        />

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary" data-test="upload-route-button">
                {{ __('Upload route') }}
            </flux:button>

            <flux:text wire:loading wire:target="gpx" class="text-sm">
                {{ __('Reading file…') }}
            </flux:text>

            <flux:text wire:loading wire:target="save" class="text-sm">
                {{ __('Analysing route…') }}
            </flux:text>
        </div>

        {{-- @guest
            <flux:text class="text-sm" data-test="guest-upload-note">
                {{ __('No account needed. The route stays readable in this browser until you create an account to keep it.') }}
            </flux:text>
        @endguest --}}
    </form>
</div>
