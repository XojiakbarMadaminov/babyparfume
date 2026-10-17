<div wire:ignore
    x-data="{
        url: null, name: '', failed: false,
        showImage(details) {
            if (!details?.url) return;
            this.url = details.url;
            this.name = details.name || 'Mahsulot rasmi';
            this.failed = false;
            this.$nextTick(() => this.$refs.imagePreview.showModal());
        },
        destroy() { this.$refs.imagePreview?.close(); }
    }"
    @open-product-image.window="showImage($event.detail)">
    <dialog x-ref="imagePreview" class="product-image-viewer" aria-label="Mahsulot rasmi"
        @click.self="$refs.imagePreview.close()" @close="url = null; failed = false">
        <div class="product-image-viewer-content">
            <header class="product-image-viewer-header">
                <h2 x-text="name"></h2>
                <button type="button" class="product-image-viewer-close" autofocus @click="$refs.imagePreview.close()">Yopish</button>
            </header>
            <template x-if="url && !failed">
                <img :src="url" :alt="name" decoding="async" x-on:error="failed = true">
            </template>
            <p x-show="failed" role="alert">Rasm yuklanmadi. Oynani yopib, qayta ochib ko‘ring.</p>
        </div>
    </dialog>
</div>
