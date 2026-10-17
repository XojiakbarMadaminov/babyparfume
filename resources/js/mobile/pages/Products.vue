<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { usePaged } from '../usePaged';
import { money } from '../api';
import type { Product } from '../types';
import Icon from '../Icon.vue';
import { session } from '../session';
import BarcodeCapture from '../BarcodeCapture.vue';

const query=ref('');
const cameraOpen=ref(false);
const imageDialog = ref<HTMLDialogElement>();
const preview = ref<{ name: string; url: string } | null>(null);
const previewFailed = ref(false);
function showImage(product: Product): void {
    const url = product.image || product.thumbnail;
    if (!url) return;
    preview.value = { name: product.name, url };
    previewFailed.value = false;
    imageDialog.value?.showModal();
}
onBeforeUnmount(() => imageDialog.value?.close());
const {result,busy,error,page,load}=usePaged<Product>('products',query);
const lastPage=computed(()=>result.value.meta?.last_page??1);
function turn(delta:number){page.value+=delta;void load();}
function capture(code: string) {
    const barcode = code.trim();
    if (!cameraOpen.value || !barcode) return;
    cameraOpen.value = false;
    if (query.value === barcode) {
        page.value = 1;
        void load(true);
    } else {
        query.value = barcode;
    }
}
</script>
<template>
    <main class="page">
        <div class="heading"><h1>Mahsulotlar</h1><p>Narx va do‘kondagi qoldiqni tekshiring.</p></div>
        <div v-if="session.data?.permissions.create_product" class="action-stack"><RouterLink class="primary" to="/products/new"><Icon name="plus" />Yangi mahsulot</RouterLink></div>
        <div class="product-search-bar">
            <label class="search-field"><Icon name="search" /><input v-model="query" type="search" maxlength="100" aria-label="Mahsulot qidirish" placeholder="Nomi yoki shtrix-kodi"></label>
            <button type="button" class="product-search-scan" aria-label="Mahsulotni shtrix-kod orqali qidirish" aria-haspopup="dialog" :aria-expanded="cameraOpen" @click="cameraOpen = true"><Icon name="scan" /><span>Skanerlash</span></button>
        </div>
        <p v-if="busy" class="loading-line" role="status">Yuklanmoqda…</p>
        <p v-if="error" class="error-banner" role="alert">{{ error }} <button class="text-button" @click="load(true)">Qayta urinish</button></p>
        <div class="list-stack products-list">
            <div v-for="product in result.data" :key="product.id" class="product-row">
                <button v-if="product.thumbnail || product.image" type="button" class="product-thumb product-preview-trigger" :aria-label="`${product.name} — rasmni kattalashtirish`" aria-haspopup="dialog" @click="showImage(product)">
                    <img :src="product.thumbnail || product.image || undefined" alt="" loading="lazy" decoding="async" width="48" height="48">
                </button>
                <span v-else class="product-thumb"><Icon name="box" /></span>
                <RouterLink class="product-info-link" :to="`/products/${product.id}`"><div class="row-info"><strong>{{ product.name }}</strong><small>{{ money(product.price) }} · {{ product.quantity }} dona</small></div><Icon name="next" /></RouterLink>
            </div>
        </div>
        <p v-if="!busy&&!error&&!result.data.length" class="empty">Mahsulot topilmadi.</p>
        <div v-if="lastPage>1" class="pagination"><button class="secondary" :disabled="busy||page===1" @click="turn(-1)">Oldingi</button><span>{{ page }} / {{ lastPage }}</span><button class="secondary" :disabled="busy||page>=lastPage" @click="turn(1)">Keyingi</button></div>
        <BarcodeCapture v-if="cameraOpen" @scanned="capture" @close="cameraOpen = false" />
        <dialog ref="imageDialog" class="product-image-dialog" aria-labelledby="product-image-heading" @click.self="imageDialog?.close()" @close="preview = null">
            <div v-if="preview" class="product-image-content">
                <header class="product-image-heading"><h2 id="product-image-heading">{{ preview.name }}</h2><button type="button" class="secondary" autofocus @click="imageDialog?.close()">Yopish</button></header>
                <img v-if="!previewFailed" :src="preview.url" :alt="preview.name" decoding="async" @error="previewFailed = true">
                <p v-else class="error-banner" role="alert">Rasm yuklanmadi. Oynani yopib, qayta ochib ko‘ring.</p>
            </div>
        </dialog>
    </main>
</template>

<style scoped>
.product-search-bar { display: grid; grid-template-columns: minmax(0, 1fr) 68px; align-items: stretch; gap: 8px; }
.product-search-bar .search-field { min-width: 0; min-height: 54px; }
.product-search-scan { display: grid; justify-items: center; align-content: center; gap: 3px; min-height: 54px; padding: 4px; border: 1px solid var(--mobile-line); border-radius: 16px; background: var(--mobile-tint); color: var(--mobile-accent); font-size: 11px; font-weight: 600; }
.product-search-scan svg { width: 20px; height: 20px; }
.product-search-scan:focus-visible { outline: 2px solid var(--mobile-accent); outline-offset: 3px; }
.product-search-scan:active { background: var(--mobile-soft); }
.product-preview-trigger { padding: 0; border: 0; cursor: zoom-in; }
.product-preview-trigger:focus-visible { outline: 2px solid var(--mobile-accent); outline-offset: 3px; }
.product-info-link { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; min-height: 54px; }
.product-info-link > svg { width: 16px; color: var(--mobile-muted); }
.product-image-dialog { position: fixed; inset: 0; width: min(960px, calc(100vw - 24px)); max-width: calc(100vw - 24px); max-height: calc(100dvh - 24px); margin: auto; padding: 0; overflow: hidden; border: 1px solid var(--mobile-line); border-radius: 18px; background: var(--mobile-card); color: var(--mobile-text); }
.product-image-dialog::backdrop { background: #000b; }
.product-image-content { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; padding: 12px; }
.product-image-heading { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 12px; }
.product-image-heading h2 { min-width: 0; max-height: 4.05em; margin: 0; overflow-y: auto; font-size: 16px; line-height: 1.35; overflow-wrap: anywhere; }
.product-image-heading button { width: auto; min-height: 40px; padding: 8px 12px; }
.product-image-content > img { display: block; width: 100%; min-width: 0; max-height: min(75dvh, calc(100dvh - 140px)); object-fit: contain; }
</style>
