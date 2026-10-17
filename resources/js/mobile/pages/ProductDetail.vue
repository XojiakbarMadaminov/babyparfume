<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, clearCache, money } from '../api';
import { session } from '../session';
import type { Product } from '../types';
import Icon from '../Icon.vue';

const route=useRoute();
const router=useRouter();
const fromScanner=window.history.state?.scannerSuccess===true;
const product=ref<Product>();
const error=ref('');
const busy=ref(false);
const deleting=ref(false);
const controller=new AbortController();
onBeforeUnmount(()=>controller.abort());
async function load(){busy.value=true;error.value='';try{product.value=(await api<{data:Product}>(`products/${route.params.id}`,{signal:controller.signal})).data;}catch(reason){if(!controller.signal.aborted)error.value=(reason as Error).message;}finally{busy.value=false;}}
async function deleteProduct(): Promise<void> {
    if (!product.value?.can_delete || deleting.value || busy.value) return;
    if (!window.confirm(`“${product.value.name}” mahsulotini o‘chirishni tasdiqlaysizmi?`)) return;
    deleting.value = true;
    error.value = '';
    try {
        await api(`products/${product.value.id}`, { method: 'DELETE', signal: controller.signal });
        clearCache();
        session.notice = 'Mahsulot o‘chirildi.';
        await router.replace('/products');
    } catch (reason) {
        if (!controller.signal.aborted) error.value = (reason as Error).message;
    } finally {
        deleting.value = false;
    }
}
void load();
</script>
<template>
    <main class="page">
        <header class="product-detail-toolbar">
            <RouterLink class="back-link" to="/products"><Icon name="back" />Mahsulotlar</RouterLink>
            <div class="product-detail-actions" :inert="deleting">
                <RouterLink v-if="product?.can_update" class="product-detail-action product-edit-action" :to="`/products/${product.id}/edit`" aria-label="Mahsulotni tahrirlash" title="Mahsulotni tahrirlash"><Icon name="edit" /><span class="sr-only">Tahrirlash</span></RouterLink>
                <button v-if="product?.can_delete" type="button" class="product-detail-action product-delete-action" :disabled="deleting || busy" aria-label="Mahsulotni o‘chirish" title="Mahsulotni o‘chirish" @click="deleteProduct"><Icon name="trash" /><span class="sr-only">O‘chirish</span></button>
            </div>
        </header>
        <p v-if="deleting" role="status">O‘chirilmoqda…</p>
        <p v-if="busy" role="status">Yuklanmoqda…</p>
        <p v-if="error" class="error-banner" role="alert">{{ error }} <button class="text-button" @click="load">Qayta urinish</button></p>
        <template v-if="product">
            <p v-if="fromScanner" class="scanner-success" role="status"><Icon name="check" />Mahsulot topildi</p>
            <div class="product-hero"><img v-if="product.image" :src="product.image" :alt="product.name"><Icon v-else name="box" /></div>
            <div class="heading"><h1>{{ product.name }}</h1><p>{{ product.type==='package'?'Qadoqli mahsulot':'Razmerli mahsulot' }}</p></div>
            <div :class="{ facts: product.initial_price !== undefined }">
                <section class="summary"><small>Sotuv narxi</small><strong>{{ money(product.price) }}</strong></section>
                <section v-if="product.initial_price !== undefined" class="summary"><small>Kelgan narxi</small><strong>{{ money(product.initial_price) }}</strong></section>
            </div>
            <section class="summary"><small>Joriy do‘kondagi qoldiq</small><strong>{{ product.quantity }} dona</strong></section>
            <section class="summary"><small>Shtrix-kod</small><strong class="barcode-value">{{ product.barcode||'Kiritilmagan' }}</strong></section>
            <RouterLink class="primary" to="/scan"><Icon name="scan" />Yana skanerlash</RouterLink>
        </template>
    </main>
</template>

<style scoped>
.product-detail-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
.product-detail-toolbar .back-link { margin-bottom: 0; }
.product-detail-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.product-detail-action { display: grid; place-items: center; width: 48px; min-height: 48px; flex-shrink: 0; border-radius: 14px; color: var(--mobile-accent); background: var(--mobile-tint); border: 1px solid var(--mobile-line); }
.product-detail-action:focus-visible { outline: 2px solid currentColor; outline-offset: 3px; }
.product-edit-action:active { background: var(--mobile-soft); }
.product-delete-action { color: var(--mobile-danger); background: var(--mobile-danger-bg); border-color: currentColor; }
.product-detail-action svg { width: 22px; height: 22px; }
</style>
