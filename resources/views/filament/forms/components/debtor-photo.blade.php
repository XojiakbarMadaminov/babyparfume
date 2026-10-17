<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @once
        @vite('resources/js/app.js')
    @endonce
    <div x-data="{
            uploading: false, error: '', abort: new AbortController(),
            init() {
                this.$el.closest('form')?.addEventListener('submit', event => {
                    if (this.uploading || this.error) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        this.error = this.uploading ? 'Rasm yuklanishini kuting.' : this.error;
                    }
                }, { capture: true, signal: this.abort.signal });
            },
            destroy() { this.abort.abort(); }
        }"
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
        @if ($getRecord()?->photo_path)
            <p class="mb-3 text-sm">Yangi rasm tanlasangiz, mavjud rasm almashtiriladi.</p>
        @endif
        <div wire:ignore x-bind:inert="uploading"><debtor-photo-picker></debtor-photo-picker></div>
        <p x-show="uploading" role="status" class="text-sm">Rasm yuklanmoqda… Tugashini kuting.</p>
        <p x-show="error" x-text="error" role="alert" class="text-sm text-red-600"></p>
    </div>
</x-dynamic-component>
