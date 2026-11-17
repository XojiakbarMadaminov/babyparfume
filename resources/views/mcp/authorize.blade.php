@extends('mcp.layout')
@section('title', 'AI ulanishiga ruxsat')
@section('content')
    <header class="flex flex-col gap-2">
        <h1 class="text-2xl font-semibold">AI ulanishiga ruxsat</h1>
        <p><strong>{{ $client->name }}</strong> ilovasi hisobingizga ulanishni so‘rayapti.</p>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $user->name }} · Faqat tanlangan do‘kon va ruxsatlar ochiladi. Ulanishni istalgan payt bekor qilishingiz mumkin.</p>
    </header>
    <form method="POST" action="{{ route('mcp.oauth.approve') }}" class="flex flex-col gap-6 rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
        @csrf
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <fieldset class="flex flex-col gap-3">
            <legend class="pb-3 font-semibold">Ruxsat etilgan do‘konlar</legend>
            @foreach ($user->stores()->orderBy('stores.id')->get() as $store)
                <label class="flex items-center gap-3"><input type="checkbox" name="store_ids[]" value="{{ $store->id }}" @checked(in_array($store->id, old('store_ids', [])))> {{ $store->name }}</label>
            @endforeach
        </fieldset>
        <label class="flex flex-col gap-2 font-medium">Asosiy do‘kon
            <select name="default_store_id" required class="rounded-lg border border-gray-300 bg-white p-3 text-gray-900 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                <option value="">Tanlang</option>
                @foreach ($user->stores()->orderBy('stores.id')->get() as $store)
                    <option value="{{ $store->id }}" @selected((string) old('default_store_id') === (string) $store->id)>{{ $store->name }}</option>
                @endforeach
            </select>
        </label>
        <fieldset class="flex flex-col gap-3">
            <legend class="pb-3 font-semibold">Amallarga ruxsat</legend>
            @foreach (['products:read' => 'Mahsulot va qoldiqlarni ko‘rish', 'debts:read' => 'Qarzdorlar va tarixni ko‘rish', 'sales:read' => 'Savdo hisobotini ko‘rish', 'debts:write' => 'Qarz va to‘lov amallarini tasdiqqa tayyorlash'] as $permission => $label)
                @if ($permission !== 'debts:write' || config('mcp.writes_enabled'))
                    <label class="flex items-center gap-3"><input type="checkbox" name="permissions[]" value="{{ $permission }}" @checked(in_array($permission, old('permissions', [])))> {{ $label }}</label>
                @endif
            @endforeach
        </fieldset>
        <p class="text-sm text-gray-600 dark:text-gray-400">AI so‘ragan ma’lumotlar ulangan AI xizmatiga uzatiladi. Yozish amali alohida tasdiqsiz bajarilmaydi. Qarz o‘zgarsa mavjud SMS, Telegram va shaxsiy push xabarlari yuboriladi.</p>
        <button class="rounded-lg bg-indigo-600 px-5 py-3 font-semibold text-white" type="submit">Tanlangan ruxsatlar bilan ulash</button>
    </form>
    <form method="POST" action="{{ route('mcp.oauth.deny') }}">
        @csrf
        @method('DELETE')
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button class="text-red-600 dark:text-red-400" type="submit">Ulanishni rad etish</button>
    </form>
@endsection
