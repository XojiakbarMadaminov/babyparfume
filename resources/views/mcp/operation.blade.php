@extends('mcp.layout')
@section('title', 'Qarz amalini tasdiqlash')
@section('content')
    @php($labels = ['create' => 'Yangi qarz', 'debt' => 'Qarz qo‘shish', 'payment' => 'To‘lov qayd etish', 'edit' => 'Qarzni tuzatish'])
    <header class="flex flex-col gap-2">
        <h1 class="text-2xl font-semibold">{{ $labels[$operation->type] }}</h1>
        <p class="text-gray-600 dark:text-gray-400">AI tayyorlagan ma’lumotlarni tekshiring.</p>
    </header>
    <section class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
        <dl class="grid grid-cols-2 gap-4">
            <dt>Do‘kon</dt><dd class="font-semibold">{{ $operation->store->name }}</dd>
            <dt>Mijoz</dt><dd>{{ $debtor?->client?->full_name ?? $operation->payload['full_name'] ?? '—' }}</dd>
            <dt>Telefon</dt><dd>{{ $debtor?->client?->phone ?? $operation->payload['phone'] ?? '—' }}</dd>
            <dt>{{ $operation->type === 'edit' ? 'Yangi qoldiq' : 'Amal summasi' }}</dt><dd class="font-semibold">{{ number_format($operation->payload['amount'], 0, '.', ' ') }} UZS</dd>
            @if ($debtor)
                <dt>Hozirgi qarz</dt><dd>{{ number_format($debtor->amount, 0, '.', ' ') }} {{ $debtor->currency }}</dd>
            @endif
            <dt>Izoh</dt><dd class="break-words">{{ $operation->payload['note'] ?? '—' }}</dd>
            @if (isset($operation->payload['date']))
                <dt>Qarz sanasi</dt><dd>{{ $operation->payload['date'] }}</dd>
            @endif
            @if (isset($operation->payload['payment_method']))
                <dt>To‘lov usuli</dt><dd>{{ $operation->payload['payment_method'] === 'cash' ? 'Naqd' : 'Karta' }}</dd>
            @endif
            <dt>Holat</dt><dd>{{ $operation->status === 'pending' && $operation->expires_at->isPast() ? 'expired' : $operation->status }}</dd>
            <dt>Tasdiq muddati</dt><dd>{{ $operation->expires_at->format('d.m.Y H:i') }}</dd>
        </dl>
        @if ($operation->result)
            <p class="rounded-lg bg-green-50 p-3 text-green-800 dark:bg-green-950 dark:text-green-200">Bajarildi: {{ number_format($operation->result['before'], 0, '.', ' ') }} → {{ number_format($operation->result['amount'], 0, '.', ' ') }} {{ $operation->result['currency'] }}</p>
        @endif
        @foreach ($operation->deliveries as $delivery)
            <p class="text-sm">{{ strtoupper($delivery->channel) }}: {{ $delivery->status === 'no_device' ? 'Hisobga bog‘langan push qurilmasi yo‘q' : $delivery->status }}</p>
        @endforeach
    </section>
    @if ($operation->status === 'pending' && $operation->expires_at->isFuture() && config('mcp.writes_enabled'))
        <p class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">Tasdiqlasangiz, qarz o‘zgaradi. Summa o‘zgarganda mijozga mavjud SMS, Telegram kanaliga xabar va hisobingizga bog‘langan qurilmalarga Firebase bildirishnomasi yuboriladi.</p>
        <div class="flex flex-wrap gap-4">
            <form method="POST" action="{{ route('mcp.operations.approve', $operation->id) }}">
                @csrf
                <button name="confirmation" value="1" class="rounded-lg bg-indigo-600 px-5 py-3 font-semibold text-white">Tekshirdim, bajarishni tasdiqlayman</button>
            </form>
            <form method="POST" action="{{ route('mcp.operations.reject', $operation->id) }}">
                @csrf
                <button class="rounded-lg border border-gray-300 px-5 py-3 dark:border-gray-700">Rad etish</button>
            </form>
        </div>
    @endif
@endsection
