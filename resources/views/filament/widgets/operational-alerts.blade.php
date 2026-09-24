<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Alerts</x-slot>

        @php($alerts = $this->alerts())

        @forelse ($alerts as $alert)
            <div class="flex items-start gap-3 border-b border-gray-100 py-3 last:border-0 dark:border-gray-800">
                <span @class([
                    'mt-1 h-2 w-2 shrink-0 rounded-full',
                    'bg-danger-500' => $alert['level'] === 'danger',
                    'bg-warning-500' => $alert['level'] === 'warning',
                ])></span>
                <div>
                    <div class="text-sm font-medium">{{ $alert['title'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $alert['detail'] }}</div>
                </div>
            </div>
        @empty
            <p class="text-sm text-success-600">Nothing needs attention.</p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
