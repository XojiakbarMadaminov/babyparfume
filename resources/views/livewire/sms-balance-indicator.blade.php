<div wire:init="loadBalance" wire:poll.300s="loadBalance">
    @php
        $status = $balance['status'] ?? 'unavailable';
        $amount = isset($balance['balance']) ? number_format($balance['balance'], 0, '.', ' ') . ' so‘m' : '—';
        $label = match ($status) {
            'empty' => 'SMS balansi tugagan',
            'low' => 'SMS balansi kam qolgan',
            default => 'Umumiy SMS balansi',
        };
    @endphp
    <x-filament::dropdown placement="bottom-end" width="sm">
        <x-slot name="trigger">
            <button type="button" aria-label="{{ $label }}: {{ $amount }}" @class([
                'inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium',
                'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200' => !in_array($status, ['low', 'empty']),
                'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200' => $status === 'low',
                'border-red-300 bg-red-50 text-red-800 dark:border-red-700 dark:bg-red-950 dark:text-red-200' => $status === 'empty',
            ])>
                <x-filament::icon icon="heroicon-o-chat-bubble-left-ellipsis" class="h-5 w-5" />
                <span>SMS: {{ $amount }}</span>
                @if (in_array($status, ['low', 'empty']))
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4" />
                @endif
            </button>
        </x-slot>
        <div class="flex flex-col gap-3 p-4 text-sm">
            <div>
                <p class="font-semibold text-gray-950 dark:text-white">Umumiy SMS balansi</p>
                <p class="text-gray-500 dark:text-gray-400">Eskiz · barcha do‘konlar uchun</p>
            </div>
            <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ $amount }}</p>
            @if (in_array($status, ['low', 'empty']))
                <p class="text-gray-700 dark:text-gray-200">{{ $label }}. Hisobni to‘ldiring.</p>
            @endif
            <p class="text-gray-500 dark:text-gray-400">
                @if (!empty($balance['updated_at']))
                    Yangilangan: {{ \Illuminate\Support\Carbon::parse($balance['updated_at'])->timezone('Asia/Tashkent')->format('d.m.Y H:i') }}
                @else
                    Balans hali olinmagan.
                @endif
            </p>
            @if ($balance['stale'] ?? false)
                <p class="text-gray-500 dark:text-gray-400">Balans yangilanmadi. Oxirgi ma’lumot mavjud bo‘lsa, ko‘rsatiladi.</p>
            @endif
            <x-filament::button wire:click="refreshBalance" wire:loading.attr="disabled" icon="heroicon-o-arrow-path" size="sm">
                <span wire:loading.remove wire:target="refreshBalance,loadBalance">Yangilash</span>
                <span wire:loading wire:target="refreshBalance,loadBalance">Yangilanmoqda…</span>
            </x-filament::button>
        </div>
    </x-filament::dropdown>
</div>
