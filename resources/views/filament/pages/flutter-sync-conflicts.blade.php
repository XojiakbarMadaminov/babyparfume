<x-filament-panels::page>
    <div class="grid gap-4">
        @forelse ($this->conflicts() as $conflict)
            <x-filament::section wire:key="conflict-{{ $conflict->id }}">
                <div class="grid gap-3">
                    <strong>{{ $conflict->operation->operation_type }} · {{ $conflict->user->name }} · {{ $conflict->operation->device->name }}</strong>
                    <p>{{ $conflict->reason }}</p>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div><strong>Server holati</strong><pre class="overflow-auto whitespace-pre-wrap text-sm">{{ json_encode($conflict->server_state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div>
                        <div><strong>Telefondagi amal</strong><pre class="overflow-auto whitespace-pre-wrap text-sm">{{ json_encode($conflict->client_state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div>
                    </div>
                    <div>{{ ($this->reviewConflictAction)(['conflict' => $conflict->id]) }}</div>
                </div>
            </x-filament::section>
        @empty
            <p>Kutilayotgan ziddiyatlar mavjud emas.</p>
        @endforelse
    </div>
</x-filament-panels::page>
