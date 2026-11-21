<div wire:init="loadBalance" wire:poll.300s="loadBalance">
    @php
        $value = $balance['balance'] ?? null;
        $color = match (true) {
            $value === null => 'gray',
            $value < 10000 => 'red',
            $value < 20000 => 'amber',
            default => 'green',
        };
        $amount = isset($balance['balance']) ? number_format($balance['balance'], 0, '.', ' ') . ' so‘m' : '—';
        $label = match (true) {
            $value !== null && $value <= 0 => 'SMS balansi tugagan',
            $color === 'red' => 'SMS balansi juda kam qolgan',
            $color === 'amber' => 'SMS balansi kam qolgan',
            default => 'Umumiy SMS balansi',
        };
    @endphp
    <x-filament::dropdown
        placement="bottom-end"
        width="sm"
        :teleport="true"
        :flip="false"
        :shift="true"
        :size="true"
        :size-padding="12"
        max-height="calc(100dvh - 6rem)"
    >
        <x-slot name="trigger">
            <button type="button" aria-label="{{ $label }}: {{ $amount }}" @class([
                'inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium',
                'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200' => $color === 'gray',
                'border-green-300 bg-green-50 text-green-800 dark:border-green-700 dark:bg-green-950 dark:text-green-200' => $color === 'green',
                'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200' => $color === 'amber',
                'border-red-300 bg-red-50 text-red-800 dark:border-red-700 dark:bg-red-950 dark:text-red-200' => $color === 'red',
            ])>
                <x-filament::icon icon="heroicon-o-chat-bubble-left-ellipsis" class="h-5 w-5" />
                <span>SMS: {{ $amount }}</span>
                @if (in_array($color, ['red', 'amber']))
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
            @if (in_array($color, ['red', 'amber']))
                <p class="text-gray-700 dark:text-gray-200">{{ $label }}. Hisobni to‘ldiring.</p>
            @endif
            <p class="text-gray-500 dark:text-gray-400">
                @if (!empty($balance['updated_at']))
                    Yangilangan: {{ \Illuminate\Support\Carbon::parse($balance['updated_at'])->timezone('Asia/Tashkent')->format('d.m.Y H:i') }}
                @else
                    Balans hali olinmagan.
                @endif
            </p>
            <p class="text-gray-500 dark:text-gray-400">Har 5 daqiqada avtomatik yangilanadi.</p>
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
