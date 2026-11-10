<x-filament-panels::page>
    <x-filament::section heading="Umumiy e’lon">
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="grid gap-4">
                @unless ($this->firebaseConfigured)
                    <p class="text-sm text-warning-600 dark:text-warning-400">
                        Serverda Firebase loyiha ID’si va yuborish kaliti sozlanishi kerak.
                    </p>
                @endunless

                {{ $this->form }}

                <div>{{ $this->sendAction }}</div>
            </div>

            <div class="grid content-start gap-2 rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                @php
                    $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
                    $body = is_string($data['body'] ?? null) ? trim($data['body']) : '';
                @endphp
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Bildirishnoma ko‘rinishi · Oson POS</p>
                <p class="break-words font-semibold">{{ $title === '' ? 'Xabar sarlavhasi' : $title }}</p>
                <p class="whitespace-pre-wrap break-words text-sm">{{ $body === '' ? 'Xabar matni shu yerda ko‘rinadi.' : $body }}</p>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Oxirgi 25 ta xabar">
        <div wire:poll.visible.10s class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-gray-500 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-3 py-2">Xabar</th>
                        <th scope="col" class="px-3 py-2">Yuboruvchi</th>
                        <th scope="col" class="px-3 py-2">Navbatga qo‘yilgan vaqt</th>
                        <th scope="col" class="px-3 py-2">Holat</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($this->announcements as $announcement)
                        <tr wire:key="announcement-{{ $announcement->id }}">
                            <td class="min-w-48 px-3 py-3">
                                <p class="break-words font-medium">{{ $announcement->title }}</p>
                                <p class="whitespace-pre-wrap break-words text-gray-500 dark:text-gray-400">{{ $announcement->body }}</p>
                            </td>
                            <td class="px-3 py-3">{{ $announcement->sender_name }}</td>
                            <td class="whitespace-nowrap px-3 py-3">{{ $announcement->created_at->format('d.m.Y H:i') }}</td>
                            <td class="whitespace-nowrap px-3 py-3">
                                <x-filament::badge :color="match ($announcement->status) {
                                    \App\Models\PushAnnouncement::ACCEPTED => 'success',
                                    \App\Models\PushAnnouncement::FAILED => 'danger',
                                    \App\Models\PushAnnouncement::UNKNOWN => 'warning',
                                    default => 'gray',
                                }">
                                    {{ $announcement->statusLabel() }}
                                </x-filament::badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-3 text-gray-500 dark:text-gray-400">Hozircha xabar yuborilmagan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
