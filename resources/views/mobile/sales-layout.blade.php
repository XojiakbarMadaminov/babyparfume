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
    <style>
        .standalone-sub-marquee {
            display: none;
            position: relative;
            overflow: hidden;
            white-space: nowrap;
            background: #b91c1c;
            color: #ffffff;
            height: 30px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.2px;
            align-items: center;
            pointer-events: none;
            user-select: none;
            border-bottom: 1px solid rgba(0, 0, 0, 0.2);
            flex: none;
        }
        .standalone-sub-marquee .sub-marquee-track {
            display: flex;
            width: max-content;
            animation: sub-marquee-anim 22s linear infinite;
            will-change: transform;
        }
        .standalone-sub-marquee .sub-marquee-group {
            display: flex;
            align-items: center;
            gap: 16px;
            padding-right: 16px;
            flex-shrink: 0;
        }
        .standalone-sub-marquee .sub-marquee-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .standalone-sub-marquee .sub-marquee-dot {
            opacity: 0.6;
            font-size: 11px;
        }
        @keyframes sub-marquee-anim {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        @media (prefers-reduced-motion: reduce) {
            .standalone-sub-marquee .sub-marquee-track { animation-play-state: paused; }
        }
    </style>
</head>
<body>
    @if(\App\Models\GeneralSetting::isSubscriptionWarningActive())
        <div id="standalone-sub-marquee" class="standalone-sub-marquee" role="status">
            <div class="sub-marquee-track">
                <div class="sub-marquee-group">
                    <span class="sub-marquee-item">⚠️ {{ \App\Models\GeneralSetting::getSubscriptionWarningText() }}</span>
                    <span class="sub-marquee-dot">•</span>
                    <span class="sub-marquee-item">⚠️ {{ \App\Models\GeneralSetting::getSubscriptionWarningText() }}</span>
                    <span class="sub-marquee-dot">•</span>
                </div>
                <div class="sub-marquee-group" aria-hidden="true">
                    <span class="sub-marquee-item">⚠️ {{ \App\Models\GeneralSetting::getSubscriptionWarningText() }}</span>
                    <span class="sub-marquee-dot">•</span>
                    <span class="sub-marquee-item">⚠️ {{ \App\Models\GeneralSetting::getSubscriptionWarningText() }}</span>
                    <span class="sub-marquee-dot">•</span>
                </div>
            </div>
        </div>
        <script>
            try {
                if (window.self === window.top) {
                    const el = document.getElementById('standalone-sub-marquee');
                    if (el) el.style.display = 'flex';
                }
            } catch (e) {}
        </script>
    @endif
    {{ $slot }}
    @livewire('notifications')
    @filamentScripts
    @livewireScripts
</body>
</html>
