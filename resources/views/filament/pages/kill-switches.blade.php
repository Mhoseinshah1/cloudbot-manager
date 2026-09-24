<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($this->switches() as $switch)
            <x-filament::section>
                <x-slot name="heading">{{ $switch['label'] }}</x-slot>

                <div class="space-y-2">
                    <p class="text-2xl font-semibold
                        @if ($switch['value'] === true) text-success-600
                        @elseif ($switch['value'] === false) text-danger-600
                        @else text-warning-600 @endif">
                        @if ($switch['value'] === true)
                            Enabled
                        @elseif ($switch['value'] === false)
                            Disabled
                        @else
                            Not configured — treated as disabled
                        @endif
                    </p>

                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $switch['description'] }}</p>

                    <p class="text-xs text-gray-400">{{ $switch['key']->value }}</p>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
