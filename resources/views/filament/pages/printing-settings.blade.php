<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Do‘kon kompyuteri">
            <p>{{ $agent?->name ?? 'Kompyuter ulanmagan' }} · {{ $online ? 'Online' : 'Offline' }}</p>
            <p class="text-sm">Agentdagi server manzili: {{ url('/') }}</p>
            <p class="text-sm text-gray-600 dark:text-gray-400">Agent Windowsga kirilganda ishlaydi. Printerga yuborilmagan topshiriq 10 daqiqa kutiladi.</p>
            <div class="flex flex-wrap gap-3 py-3">
                <x-filament::button wire:click="issueCode">Ulash kodi yaratish</x-filament::button>
                <x-filament::button color="gray" wire:click="reload">Yangilash</x-filament::button>
                @if($agent)<x-filament::button color="danger" wire:click="revoke" wire:confirm="Kompyuter aloqasi bekor qilinsinmi?">Kompyuterni uzish</x-filament::button>@endif
            </div>
            @if($pairingCode)<p class="font-mono text-xl">{{ $pairingCode }} <span class="text-sm">· 10 daqiqa amal qiladi</span></p>@endif
        </x-filament::section>
        <form wire:submit="save" class="space-y-6">
            @if($disconnectedProfiles)
                <p role="alert" class="text-sm text-gray-600 dark:text-gray-400">Quyidagi profillarning avvalgi printeri joriy kompyuterda mavjud emas: {{ implode(', ', $disconnectedProfiles) }}. Printerni qayta tanlab, sozlamalarni saqlang.</p>
            @endif
            <x-filament::section heading="Printerdagi qog‘oz">
                @forelse($printers as $printer)
                    <div class="flex flex-wrap items-center gap-3 py-2" wire:key="printer-{{ $printer['id'] }}">
                        <label for="media-{{ $printer['id'] }}">{{ $printer['name'] }} {{ $printer['available'] ? '' : '(topilmadi)' }}</label>
                        <select id="media-{{ $printer['id'] }}" wire:model="media.{{ $printer['id'] }}" class="rounded-lg border-gray-300 bg-white text-gray-950 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                            <option value="unknown">Qog‘oz turini tanlang</option><option value="receipt">Chek lentasi</option><option value="barcode">Barcode etiketkasi</option>
                        </select>
                    </div>
                @empty<p>Agent ulangach printerlar shu yerda ko‘rinadi.</p>@endforelse
            </x-filament::section>
            @foreach($profiles as $index=>$profile)
                <x-filament::section :heading="$profile['name']" wire:key="profile-{{ $profile['id'] }}">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        @foreach(['name'=>'Profil nomi','width_mm'=>'Eni (mm)','height_mm'=>'Balandligi (mm, chekda bo‘sh)','margin_mm'=>'Margin (mm)','default_copies'=>'Standart nusxa','max_copies'=>'Maksimal nusxa'] as $field=>$label)
                            <label class="flex flex-col gap-1">{{ $label }}<x-filament::input.wrapper><x-filament::input :type="$field==='name'?'text':'number'" step="any" wire:model="profiles.{{ $index }}.{{ $field }}" /></x-filament::input.wrapper></label>
                        @endforeach
                        <label class="flex flex-col gap-1">Hujjat turi<select wire:model="profiles.{{ $index }}.document_type" class="rounded-lg border-gray-300 bg-white text-gray-950 dark:border-gray-600 dark:bg-gray-900 dark:text-white"><option value="receipt">Chek</option><option value="barcode">Barcode</option></select></label>
                        <label class="flex flex-col gap-1">Printer<select wire:key="profile-printer-{{ $profile['id'] }}-{{ sha1(implode('|', array_column($printers, 'id'))) }}" wire:model="profiles.{{ $index }}.printer_id" class="rounded-lg border-gray-300 bg-white text-gray-950 dark:border-gray-600 dark:bg-gray-900 dark:text-white"><option value="" @selected(blank($profile['printer_id']))>Biriktirilmagan</option>@foreach($printers as $printer)<option value="{{ $printer['id'] }}" @selected($profile['printer_id'] === $printer['id'])>{{ $printer['name'] }}</option>@endforeach</select></label>
                        <label class="flex flex-col gap-1">Barcode formati<select wire:model="profiles.{{ $index }}.symbology" class="rounded-lg border-gray-300 bg-white text-gray-950 dark:border-gray-600 dark:bg-gray-900 dark:text-white"><option value="auto">Avtomatik</option><option value="EAN13">EAN13</option><option value="C128">Code128</option></select></label>
                    </div>
                    <div class="flex flex-wrap gap-5 py-4">@foreach(['active'=>'Faol','selectable'=>'Kassir tanlay oladi','is_default'=>'Standart','show_price'=>'Etiketkada narx'] as $field=>$label)<label class="flex items-center gap-2"><input type="checkbox" wire:model="profiles.{{ $index }}.{{ $field }}">{{ $label }}</label>@endforeach</div>
                    @if($profile['printer_id'])<x-filament::button type="button" color="gray" wire:click="testProfile('{{ $profile['id'] }}')" wire:confirm="Saqlangan profil bilan bitta TEST hujjati printerga yuborilsinmi?">Test hujjati chiqarish</x-filament::button>@endif
                </x-filament::section>
            @endforeach
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="autoReceipt">Sotuvdan keyin standart chekni avtomatik chiqarish</label>
            @foreach($errors->all() as $error)<p class="text-red-600 dark:text-red-400">{{ $error }}</p>@endforeach
            <div class="flex flex-wrap gap-3"><x-filament::button type="submit" wire:loading.attr="disabled">Sozlamalarni saqlash</x-filament::button><x-filament::button type="button" color="gray" wire:click="addProfile">O‘lcham qo‘shish</x-filament::button></div>
        </form>
        <x-filament::section heading="Oxirgi chop etish topshiriqlari">
            <div class="space-y-2">@forelse($jobs as $job)<div class="flex flex-wrap gap-3 border-b border-gray-200 py-2 dark:border-gray-700"><span>{{ $job->created_at->format('d.m H:i') }}</span><span>{{ $job->document->profile_snapshot['name'] }}</span><span>{{ $job->status }}</span><span>{{ $job->error_code }}</span></div>@empty<p>Topshiriqlar mavjud emas.</p>@endforelse</div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
