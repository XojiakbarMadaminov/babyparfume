@extends('mcp.layout')
@section('title', 'AI / MCP ulanishlari')
@section('content')
    <header class="flex flex-col gap-2">
        <h1 class="text-2xl font-semibold">AI / MCP ulanishlari</h1>
        <p class="text-gray-600 dark:text-gray-400">AI klientiga quyidagi server manzilini OAuth orqali ulang.</p>
        <code class="break-all rounded-lg bg-gray-100 p-3 text-sm dark:bg-gray-800">{{ route('mcp.endpoint') }}</code>
        <p class="text-sm">Yozish: {{ config('mcp.writes_enabled') ? 'yoqilgan' : 'o‘chirilgan' }}. Mavjud foydalanuvchi huquqlari har bir amalda tekshiriladi.</p>
    </header>
    @forelse ($connections as $connection)
        <article class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
            <div class="flex justify-between gap-4">
                <h2 class="font-semibold">{{ $clients[$connection->client_id] ?? 'AI klienti' }}</h2>
                <span class="text-sm">{{ $connection->revoked_at ? 'Bekor qilingan' : 'Faol' }}</span>
            </div>
            <p class="text-sm">Oxirgi ishlatilgan: {{ $connection->last_used_at?->format('d.m.Y H:i') ?? 'Hali ishlatilmagan' }}</p>
            <p class="text-sm">Ruxsatlar: {{ implode(', ', $connection->permissions) }}</p>
            @unless ($connection->revoked_at)
                <form method="POST" action="{{ route('mcp.connections.default-store', $connection) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="flex flex-col gap-2 text-sm">Asosiy do‘kon
                        <select name="store_id" class="rounded-lg border border-gray-300 bg-white p-2 text-gray-900 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                            @foreach ($stores->whereIn('id', $connection->store_ids) as $store)
                                <option value="{{ $store->id }}" @selected($connection->default_store_id === $store->id)>{{ $store->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm text-white">Saqlash</button>
                </form>
                <form method="POST" action="{{ route('mcp.connections.revoke', $connection) }}">
                    @csrf
                    <button class="text-sm text-red-600 dark:text-red-400">Ulanishni va uning tokenlarini bekor qilish</button>
                </form>
            @endunless
            @if ($connection->operations->isNotEmpty())
                <div class="flex flex-col gap-2 border-t border-gray-200 pt-4 dark:border-gray-800">
                    <h3 class="text-sm font-semibold">Oxirgi amallar</h3>
                    @foreach ($connection->operations as $operation)
                        <a class="flex justify-between gap-3 text-sm text-indigo-600 dark:text-indigo-400" href="{{ route('mcp.operations.show', $operation->id) }}">
                            <span>{{ $operation->created_at->format('d.m.Y H:i') }} · {{ $operation->type }}</span>
                            <span>{{ $operation->status === 'pending' && $operation->expires_at->isPast() ? 'expired' : $operation->status }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </article>
    @empty
        <p class="rounded-xl border border-dashed border-gray-300 p-6 dark:border-gray-700">Hali AI ulanishi yo‘q. Server manzilini AI klientiga qo‘shib, OAuth orqali hisobingizga kiring.</p>
    @endforelse
@endsection
