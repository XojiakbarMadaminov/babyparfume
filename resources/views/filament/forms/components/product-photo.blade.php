<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @once
        @vite('resources/js/app.js')
    @endonce
    <div x-data="{
            processing: false, uploading: false, error: '', abort: new AbortController(),
            init() {
                this.$el.closest('form')?.addEventListener('submit', event => {
                    if (this.processing || this.uploading || this.error) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                    }
                }, { capture: true, signal: this.abort.signal });
            },
            destroy() { this.abort.abort(); }
        }"
        x-on:photo-processing="processing = $event.detail"
        x-on:photo-selected="
            error = '';
            if ($event.detail) {
                uploading = true;
                $wire.upload(@js($getStatePath()), $event.detail,
                    () => { uploading = false; error = ''; },
                    () => { uploading = false; error = 'Rasm yuklanmadi. Qayta tanlang.'; });
            } else {
                $wire.set(@js($getStatePath()), null);
            }
        ">
        @if ($url = $getRecord()?->getPrimaryImageUrl())
            <a href="{{ $url }}" target="_blank" rel="noopener">
                <img src="{{ $url }}" alt="Mahsulot rasmi" loading="lazy" decoding="async" style="max-height: 200px; max-width: 100%; object-fit: contain;">
            </a>
            <p class="text-sm">Yangi rasm tanlasangiz, mavjud rasm almashtiriladi.</p>
        @endif
        <div wire:ignore x-bind:inert="uploading"><debtor-photo-picker optimized></debtor-photo-picker></div>
        <p x-show="processing || uploading" role="status" class="text-sm">Rasm tayyorlanmoqda va yuklanmoqda… Tugashini kuting.</p>
        <p x-show="error" x-text="error" role="alert" class="text-sm text-red-600"></p>
    </div>
</x-dynamic-component>
