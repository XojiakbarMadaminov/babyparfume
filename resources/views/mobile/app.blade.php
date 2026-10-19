<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#b96c14">
    <meta name="robots" content="noindex, nofollow">
    <title>Baby Parfume · Mobil</title>
    @vite('resources/js/mobile/main.ts')
</head>
<body>
    <div id="mobile-app"
        data-api="{{ url('/mobile/api/v1') }}"
        data-base="{{ url('/mobile') }}"
        data-sub-warning="{{ \App\Models\GeneralSetting::isSubscriptionWarningActive() ? '1' : '0' }}"
        data-sub-text="{{ \App\Models\GeneralSetting::getSubscriptionWarningText() }}">
        <p style="padding:24px;font-family:system-ui">Mobil yordamchi yuklanmoqda…</p>
    </div>
    <noscript>Mobil yordamchi uchun JavaScriptni yoqing.</noscript>
</body>
</html>
