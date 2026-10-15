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
<template><main class="page"><RouterLink class="back-link" to="/products"><Icon name="back" />Mahsulotlar</RouterLink><p v-if="busy" role="status">Yuklanmoqda…</p><p v-if="error" class="error-banner" role="alert">{{ error }} <button class="text-button" @click="load">Qayta urinish</button></p><template v-if="product"><p v-if="fromScanner" class="scanner-success" role="status"><Icon name="check" />Mahsulot topildi</p><div class="product-hero"><img v-if="product.image" :src="product.image" :alt="product.name"><Icon v-else name="box" /></div><div class="heading"><h1>{{ product.name }}</h1><p>{{ product.type==='package'?'Qadoqli mahsulot':'Razmerli mahsulot' }}</p></div><div class="facts"><section class="summary"><small>Sotuv narxi</small><strong>{{ money(product.price) }}</strong></section><section class="summary"><small>Joriy do‘kondagi qoldiq</small><strong>{{ product.quantity }} dona</strong></section></div><section class="summary"><small>Shtrix-kod</small><strong class="barcode-value">{{ product.barcode||'Kiritilmagan' }}</strong></section><RouterLink class="primary" to="/scan"><Icon name="scan" />Yana skanerlash</RouterLink></template></main></template>
