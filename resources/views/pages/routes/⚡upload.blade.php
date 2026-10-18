<?php

use App\Actions\Routes\InvalidGpxFile;
use App\Actions\Routes\StoreGpxRoute;
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

    public ?TemporaryUploadedFile $gpx = null;

    /**
     * Validate and store the uploaded GPX file as a route.
     */
    public function save(StoreGpxRoute $storeGpxRoute): void
    {
        $this->validate();

        // Parsing and storing a large GPX is not free, and neither is the
        // disk it lands on.
        $key = 'upload-route:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, self::UPLOADS_PER_MINUTE)) {
            $this->addError('gpx', __('That is a lot of uploading. Try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($key),
            ]));

            return;
        }

        RateLimiter::increment($key);

        try {
            $route = $storeGpxRoute->handle(Auth::user(), $this->gpx);
        } catch (InvalidGpxFile $e) {
            $this->addError('gpx', $e->getMessage());

            return;
        }

        $this->reset('gpx');

        Flux::toast(variant: 'success', text: __('":name" uploaded.', ['name' => $route->name]));

        $this->dispatch('route-uploaded', routeId: $route->id);
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
            :label="__('GPX file')"
            :description="__('Export a route from Ride with GPS, then upload it here.')"
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
    </form>
</div>
