<?php

use App\Models\RouteAnalysis;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Riding settings')] class extends Component {
    public string $riskTolerance = RouteAnalysis::TOLERANCE_NORMAL;

    public function mount(): void
    {
        $this->riskTolerance = Auth::user()->risk_tolerance ?: RouteAnalysis::TOLERANCE_NORMAL;
    }

    /**
     * Save how much traffic this rider is willing to put up with.
     */
    public function updateRidingPreferences(): void
    {
        $validated = $this->validate();

        Auth::user()->update(['risk_tolerance' => $validated['riskTolerance']]);

        Flux::toast(variant: 'success', text: __('Riding preferences updated.'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'riskTolerance' => ['required', Rule::in(array_keys(RouteAnalysis::THRESHOLDS))],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['riskTolerance' => __('risk tolerance')];
    }

    /**
     * The score at which each setting starts flagging a road.
     *
     * @return array<string, int>
     */
    public function thresholds(): array
    {
        return RouteAnalysis::THRESHOLDS;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Riding settings') }}</flux:heading>

    <x-pages::settings.layout
        :heading="__('Riding')"
        :subheading="__('How cautious should route analysis be on your behalf')"
    >
        <form wire:submit="updateRidingPreferences" class="my-6 w-full space-y-6">
            <flux:radio.group wire:model="riskTolerance" :label="__('Risk tolerance')" variant="cards" class="flex-col">
                <flux:radio
                    value="cautious"
                    :label="__('Cautious')"
                    :description="__('Flag any road scoring :score or more. Most numbered routes will be flagged.', ['score' => $this->thresholds()['cautious']])"
                />

                <flux:radio
                    value="normal"
                    :label="__('Normal')"
                    :description="__('Flag roads scoring :score or more. Busy roads are flagged, quiet lanes are not.', ['score' => $this->thresholds()['normal']])"
                />

                <flux:radio
                    value="brave"
                    :label="__('Brave')"
                    :description="__('Flag only roads scoring :score or more: the genuinely hostile ones.', ['score' => $this->thresholds()['brave']])"
                />
            </flux:radio.group>

            <flux:text class="text-sm">
                {{ __('This is the starting point for every route you analyse. You can still change it per route.') }}
            </flux:text>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="update-riding-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
