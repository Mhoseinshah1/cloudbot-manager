<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Confirm it is you</x-slot>

        <p class="text-sm">
            Enter the six-digit code from your authenticator app. If you no longer
            have it, enter one of your recovery codes instead.
        </p>

        <form wire:submit="submit" class="mt-6 flex items-end gap-3">
            <div>
                <label for="code" class="block text-sm font-medium">Code</label>
                <input
                    id="code"
                    wire:model="code"
                    autocomplete="one-time-code"
                    autofocus
                    class="mt-1 block w-56 rounded-lg border-gray-300 font-mono shadow-sm dark:bg-gray-900"
                />
            </div>

            <x-filament::button type="submit">Continue</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
