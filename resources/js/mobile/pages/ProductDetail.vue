<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api, money } from '../api';
import type { Product } from '../types';
import Icon from '../Icon.vue';

const route=useRoute();
const fromScanner=window.history.state?.scannerSuccess===true;
const product=ref<Product>();
const error=ref('');
const busy=ref(false);
const controller=new AbortController();
onBeforeUnmount(()=>controller.abort());
async function load(){busy.value=true;error.value='';try{product.value=(await api<{data:Product}>(`products/${route.params.id}`,{signal:controller.signal})).data;}catch(reason){if(!controller.signal.aborted)error.value=(reason as Error).message;}finally{busy.value=false;}}
void load();
</script>
<template><main class="page"><header class="product-detail-toolbar"><RouterLink class="back-link" to="/products"><Icon name="back" />Mahsulotlar</RouterLink><RouterLink v-if="product?.can_update" class="product-edit-action" :to="`/products/${product.id}/edit`" aria-label="Mahsulotni tahrirlash" title="Mahsulotni tahrirlash"><Icon name="edit" /><span class="sr-only">Tahrirlash</span></RouterLink></header><p v-if="busy" role="status">Yuklanmoqda…</p><p v-if="error" class="error-banner" role="alert">{{ error }} <button class="text-button" @click="load">Qayta urinish</button></p><template v-if="product"><p v-if="fromScanner" class="scanner-success" role="status"><Icon name="check" />Mahsulot topildi</p><div class="product-hero"><img v-if="product.image" :src="product.image" :alt="product.name"><Icon v-else name="box" /></div><div class="heading"><h1>{{ product.name }}</h1><p>{{ product.type==='package'?'Qadoqli mahsulot':'Razmerli mahsulot' }}</p></div><div class="facts"><section class="summary"><small>Sotuv narxi</small><strong>{{ money(product.price) }}</strong></section><section class="summary"><small>Joriy do‘kondagi qoldiq</small><strong>{{ product.quantity }} dona</strong></section></div><section class="summary"><small>Shtrix-kod</small><strong class="barcode-value">{{ product.barcode||'Kiritilmagan' }}</strong></section><RouterLink class="primary" to="/scan"><Icon name="scan" />Yana skanerlash</RouterLink></template></main></template>

<style scoped>
.product-detail-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
.product-detail-toolbar .back-link { margin-bottom: 0; }
.product-edit-action { display: grid; place-items: center; width: 48px; min-height: 48px; flex-shrink: 0; border-radius: 14px; color: var(--mobile-accent); background: var(--mobile-tint); border: 1px solid var(--mobile-line); }
.product-edit-action:focus-visible { outline: 2px solid var(--mobile-accent); outline-offset: 3px; }
.product-edit-action:active { background: var(--mobile-soft); }
.product-edit-action svg { width: 22px; height: 22px; }
</style>
