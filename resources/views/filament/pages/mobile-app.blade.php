<x-filament-panels::page>
    @if (session('status'))
        <p role="status" class="text-sm text-success-600 dark:text-success-400">{{ session('status') }}</p>
    @endif

    <x-filament::section heading="OSON POS — Android ilovasi">
        <div class="flex flex-col gap-5">
            @if ($release)
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div><dt class="text-sm text-gray-500 dark:text-gray-400">Versiya</dt><dd class="font-semibold">{{ $release->version_name }} (build {{ $release->version_code }})</dd></div>
                    <div><dt class="text-sm text-gray-500 dark:text-gray-400">Yuklangan sana</dt><dd>{{ $release->created_at->format('d.m.Y H:i') }}</dd></div>
                    <div><dt class="text-sm text-gray-500 dark:text-gray-400">Fayl hajmi</dt><dd>{{ number_format($release->file_size / 1024 / 1024, 1) }} MB</dd></div>
                </dl>
                @if ($release->release_notes)
                    <div class="flex flex-col gap-2"><h3 class="font-semibold">Yangiliklar</h3><p class="whitespace-pre-wrap text-sm">{{ $release->release_notes }}</p></div>
                @endif
                @if ($downloadAvailable)
                    <div><x-filament::button tag="a" :href="route('mobile-app.download')" icon="heroicon-o-arrow-down-tray">APK yuklab olish</x-filament::button></div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Telefoningizda faylni oching va Android so‘raganda ushbu manbadan o‘rnatishga ruxsat bering.</p>
                @else
                    <p role="alert" class="text-sm text-danger-600 dark:text-danger-400">APK fayli topilmadi. Super adminga murojaat qiling.</p>
                @endif
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">Hozircha APK yuklanmagan.</p>
            @endif
        </div>
    </x-filament::section>

    @if ($canUpload)
        <x-filament::section heading="Yangi APK yuklash">
            <form action="{{ route('mobile-app.releases.store') }}" method="POST" enctype="multipart/form-data" class="flex flex-col gap-5">
                @csrf
                @if ($errors->any())
                    <ul role="alert" class="flex flex-col gap-1 text-sm text-danger-600 dark:text-danger-400">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
                <div class="flex flex-col gap-2">
                    <label for="apk" class="text-sm font-medium">APK fayli (maksimal 150 MB)</label>
                    <input id="apk" name="apk" type="file" accept=".apk" required class="block w-full text-sm" />
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="flex flex-col gap-2">
                        <label for="version_name" class="text-sm font-medium">Versiya nomi (masalan, 0.1.6)</label>
                        <x-filament::input.wrapper><x-filament::input id="version_name" name="version_name" :value="old('version_name')" maxlength="40" required /></x-filament::input.wrapper>
                    </div>
                    <div class="flex flex-col gap-2">
                        <label for="version_code" class="text-sm font-medium">Versiya kodi (build raqami)</label>
                        <x-filament::input.wrapper><x-filament::input id="version_code" name="version_code" type="number" :value="old('version_code')" :min="($release?->version_code ?? 0) + 1" max="2100000000" required /></x-filament::input.wrapper>
                    </div>
                </div>
                <div class="flex flex-col gap-2">
                    <label for="release_notes" class="text-sm font-medium">Yangiliklar (ixtiyoriy)</label>
                    <x-filament::input.wrapper><textarea id="release_notes" name="release_notes" rows="4" maxlength="10000" class="block w-full border-0 bg-transparent p-3 text-sm text-gray-950 focus:ring-0 dark:text-white">{{ old('release_notes') }}</textarea></x-filament::input.wrapper>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Versiya nomi va kodi APK ichidagi raqamlarga mos bo‘lsin. Yangilanish uchun APK avvalgi ilova bilan bir xil signing key orqali imzolangan bo‘lishi kerak. Bu tekshiruv APK tuzilishini tekshiradi, imzosini emas.</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Serverda upload_max_filesize kamida 150M, post_max_size esa undan katta (masalan, 160M) bo‘lishi kerak.</p>
                <div><x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">APK yuklash</x-filament::button></div>
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>
