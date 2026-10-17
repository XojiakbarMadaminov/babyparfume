<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePaged } from '../usePaged';
import { money } from '../api';
import type { Product } from '../types';
import Icon from '../Icon.vue';
import { session } from '../session';

const query=ref('');
const {result,busy,error,page,load}=usePaged<Product>('products',query);
const lastPage=computed(()=>result.value.meta?.last_page??1);
function turn(delta:number){page.value+=delta;void load();}
</script>
<template>
    <main class="page">
        <div class="heading"><h1>Mahsulotlar</h1><p>Narx va do‘kondagi qoldiqni tekshiring.</p></div>
        <div v-if="session.data?.permissions.create_product" class="action-stack"><RouterLink class="primary" to="/products/new"><Icon name="plus" />Yangi mahsulot</RouterLink></div>
        <label class="search-field"><Icon name="search" /><input v-model="query" type="search" maxlength="100" aria-label="Mahsulot qidirish" placeholder="Nomi yoki shtrix-kodi"></label>
        <p v-if="busy" class="loading-line" role="status">Yuklanmoqda…</p>
        <p v-if="error" class="error-banner" role="alert">{{ error }} <button class="text-button" @click="load(true)">Qayta urinish</button></p>
        <div class="list-stack products-list"><RouterLink v-for="product in result.data" :key="product.id" class="product-row" :to="`/products/${product.id}`"><span class="product-thumb"><img v-if="product.image" :src="product.image" alt="" loading="lazy"><Icon v-else name="box" /></span><div class="row-info"><strong>{{ product.name }}</strong><small>{{ money(product.price) }} · {{ product.quantity }} dona</small></div><Icon name="next" /></RouterLink></div>
        <p v-if="!busy&&!error&&!result.data.length" class="empty">Mahsulot topilmadi.</p>
        <div v-if="lastPage>1" class="pagination"><button class="secondary" :disabled="busy||page===1" @click="turn(-1)">Oldingi</button><span>{{ page }} / {{ lastPage }}</span><button class="secondary" :disabled="busy||page>=lastPage" @click="turn(1)">Keyingi</button></div>
    </main>
</template>
