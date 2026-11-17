<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'AI / MCP') — Oson POS</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
    <main class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-10">
        <nav class="flex items-center justify-between gap-4">
            <a class="font-semibold" href="{{ route('filament.admin.pages.dashboard') }}">Oson POS</a>
            <a class="text-sm text-indigo-600 dark:text-indigo-400" href="{{ route('mcp.connections.index') }}">AI / MCP ulanishlari</a>
        </nav>
        @if (session('status'))
            <p role="status" class="rounded-xl bg-green-50 p-4 text-green-800 dark:bg-green-950 dark:text-green-200">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-red-50 p-4 text-red-800 dark:bg-red-950 dark:text-red-200">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
        @yield('content')
    </main>
</body>
</html>
