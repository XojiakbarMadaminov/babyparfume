<!DOCTYPE html>
<html lang="uz" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Sotuv · Baby Parfume</title>
    @livewireStyles
    @filamentStyles
    @vite('resources/js/mobile/sales.ts')
</head>
<body>
    {{ $slot }}
    @livewire('notifications')
    @filamentScripts
    @livewireScripts
</body>
</html>
