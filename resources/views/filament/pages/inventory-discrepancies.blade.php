<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Servers we believe in that the provider could not confirm</x-slot>

        @forelse ($this->missingServers() as $server)
            <div class="flex items-center justify-between border-b border-gray-100 py-2 text-sm dark:border-gray-800">
                <span>{{ $server->name }} — {{ $server->user?->name ?? 'unknown customer' }}</span>
                <span class="text-danger-600">{{ $server->status->value }}</span>
            </div>
        @empty
            <p class="text-sm text-gray-500">Nothing missing.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Recorded discrepancies</x-slot>

        @forelse ($this->discrepancies() as $message)
            <div class="border-b border-gray-100 py-2 text-sm dark:border-gray-800">
                <div class="font-medium">{{ data_get($message->payload, 'kind', 'discrepancy') }}</div>
                <div class="text-xs text-gray-500">
                    {{ $message->created_at?->toDateTimeString() }} ·
                    {{ data_get($message->payload, 'provider_code', '—') }} ·
                    server {{ data_get($message->payload, 'provider_server_id', '—') }}
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">No discrepancies recorded. Run a reconcile to check.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
