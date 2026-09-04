<x-filament-panels::page>
    @if ($confirmed && $recoveryCodes === [])
        <x-filament::section>
            <x-slot name="heading">Two-factor authentication is active</x-slot>

            <p class="text-sm">
                This account is protected by an authenticator app. To move to a new
                device, an operator must reset two-factor authentication for you.
            </p>
        </x-filament::section>
    @elseif ($recoveryCodes !== [])
        <x-filament::section>
            <x-slot name="heading">Save your recovery codes</x-slot>

            <p class="text-sm">
                Each code can be used once if you lose access to your authenticator
                app. They are shown now and cannot be shown again.
            </p>

            <div class="mt-4 grid grid-cols-2 gap-2 font-mono text-sm">
                @foreach ($recoveryCodes as $recoveryCode)
                    <div class="rounded bg-gray-100 px-3 py-2 dark:bg-gray-800">{{ $recoveryCode }}</div>
                @endforeach
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Set up two-factor authentication</x-slot>

            <p class="text-sm">
                Administrator accounts require a second factor. Scan this code with an
                authenticator app, then enter the six-digit code it shows.
            </p>

            <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-center">
                <div class="w-[220px]">{!! $this->qrCodeSvg() !!}</div>

                <div class="text-sm">
                    <p class="font-medium">If you cannot scan the code</p>
                    <p class="mt-1">Enter this key manually:</p>
                    <p class="mt-1 font-mono break-all">{{ $secret }}</p>
                </div>
            </div>

            <form wire:submit="confirm" class="mt-6 flex items-end gap-3">
                <div>
                    <label for="code" class="block text-sm font-medium">Six-digit code</label>
                    <input
                        id="code"
                        wire:model="code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        class="mt-1 block w-40 rounded-lg border-gray-300 font-mono shadow-sm dark:bg-gray-900"
                    />
                </div>

                <x-filament::button type="submit">Confirm</x-filament::button>
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>
