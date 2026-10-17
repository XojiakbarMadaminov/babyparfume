<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, ApiError, clearCache } from '../api';
import { session } from '../session';
import type { Product } from '../types';
import BarcodeCapture from '../BarcodeCapture.vue';
import Icon from '../Icon.vue';

interface StockOption { id: number; name: string; is_active?: boolean }
interface StockQuantity { stock_id: number; quantity: number | string }
interface SizeRow { key: number; id?: number; size: string; stocks: StockQuantity[] }
interface EditInventory { stocks: StockOption[]; quantities: StockQuantity[]; sizes: Omit<SizeRow, 'key'>[] }
const router = useRouter();
const route = useRoute();
const editing = Boolean(route.params.id);
const allowed = ref(editing || !!session.data?.permissions.create_product);
const backTo = editing ? `/products/${route.params.id}` : '/products';
const name = ref('');
const barcode = ref('');
const type = ref('package');
const originalType = ref('package');
const initialPrice = ref<number | string>('');
const price = ref<number | string>('');
const stocks = ref<StockOption[]>([]);
const quantities = ref<StockQuantity[]>([]);
const sizes = ref<SizeRow[]>([]);
const loading = ref(true);
const optionsReady = ref(false);
const busy = ref(false);
const generating = ref(false);
const cameraOpen = ref(false);
const scanned = ref(false);
const barcodeInput = ref<HTMLInputElement>();
const error = ref('');
const errors = ref<Record<string, string[]>>({});
const controller = new AbortController();
const storeId = session.data!.store_id;
let sizeKey = 0;
onBeforeUnmount(() => controller.abort());
function stockRows(): StockQuantity[] { return stocks.value.map(stock => ({ stock_id: stock.id, quantity: 0 })); }
function addSize() { sizes.value.push({ key: ++sizeKey, size: '', stocks: stockRows() }); }
function removeSize(index: number) {
    if (editing && sizes.value[index].id && !window.confirm('Razmer va unga tegishli qoldiqlar saqlashda olib tashlanadi. Davom etasizmi?')) return;
    sizes.value.splice(index, 1);
}
async function load() {
    loading.value = true; error.value = '';
    try {
        if (editing) {
            const { data, edit } = await api<{ data: Product; edit: EditInventory }>(`products/${route.params.id}`, { signal: controller.signal });
            allowed.value = !!data.can_update;
            if (!allowed.value) throw new ApiError(403, 'Mahsulotni tahrirlash uchun ruxsat yo‘q.');
            name.value = data.name; barcode.value = data.barcode ?? ''; type.value = data.type;
            initialPrice.value = data.initial_price ?? ''; price.value = data.price;
            originalType.value = data.type;
            stocks.value = edit.stocks;
            quantities.value = stockRows().map(row => ({ ...row, quantity: edit.quantities.find(stock => stock.stock_id === row.stock_id)?.quantity ?? 0 }));
            sizes.value = edit.sizes.map(row => ({ ...row, key: ++sizeKey, size: String(row.size) }));
            if (!sizes.value.length) addSize();
        } else {
            const result = await api<{ stocks: StockOption[] }>('products/create-options', { signal: controller.signal });
            stocks.value = result.stocks; quantities.value = stockRows(); sizes.value = []; addSize();
        }
        optionsReady.value = true;
    } catch (reason) { if (!controller.signal.aborted) error.value = (reason as Error).message; }
    finally { loading.value = false; }
}
if (allowed.value) void load();
else loading.value = false;
async function generate() {
    if (generating.value || busy.value) return;
    generating.value = true; error.value = '';
    try {
        const result = await api<{ barcode: string }>('products/barcode', { signal: controller.signal });
        barcode.value = result.barcode; scanned.value = false; delete errors.value.barcode;
    } catch (reason) { if (!controller.signal.aborted) error.value = (reason as Error).message; }
    finally { generating.value = false; }
}
async function capture(code: string) {
    barcode.value = code; cameraOpen.value = false; scanned.value = true; delete errors.value.barcode;
    await nextTick(); barcodeInput.value?.focus();
}
function stockPayload(rows: StockQuantity[]) { return rows.map(row => ({ stock_id: row.stock_id, quantity: Number(row.quantity) })); }
async function submit() {
    if (busy.value || generating.value) return;
    if (editing && type.value !== originalType.value && !window.confirm('Mahsulot turi almashtiriladi. Eski turdagi razmer va qoldiqlar o‘rniga kiritilgan yangi qoldiqlar saqlanadi. Davom etasizmi?')) return;
    busy.value = true; error.value = ''; errors.value = {};
    try {
        const result = await api<{ data: Product }>(editing ? `products/${route.params.id}` : 'products', { signal: controller.signal, ...(editing ? { method: 'PATCH' as const } : {}), body: {
            store_id: storeId, name: name.value, barcode: barcode.value,
            initial_price: Number(initialPrice.value), price: Number(price.value),
            type: type.value, ...(type.value === 'package' ? { stocks: stockPayload(quantities.value) }
                : { sizes: sizes.value.map(row => ({ ...(editing && row.id ? { id: row.id } : {}), size: row.size, stocks: stockPayload(row.stocks) })) }),
        } });
        clearCache(); session.notice = editing ? 'Mahsulot yangilandi.' : 'Yangi mahsulot qo‘shildi.';
        await router.replace(`/products/${result.data.id}`);
    } catch (reason) {
        if (!controller.signal.aborted) {
            error.value = (reason as Error).message;
            if (reason instanceof ApiError) errors.value = reason.errors;
        }
    } finally { busy.value = false; }
}
</script>
<template>
    <main class="page product-form-page">
        <RouterLink class="back-link" :to="backTo"><Icon name="back" />Orqaga</RouterLink>
        <div class="heading"><h1>{{ editing ? 'Mahsulotni tahrirlash' : 'Yangi mahsulot' }}</h1><p>{{ editing ? 'Ma’lumotlar, mahsulot turi, razmerlar va ombor qoldig‘ini yangilang.' : 'Mahsulot ma’lumotlari va ombordagi miqdorini kiriting.' }}</p></div>
        <p v-if="!allowed" class="empty">{{ editing ? 'Mahsulotni tahrirlash uchun ruxsat yo‘q.' : 'Mahsulot yaratish uchun ruxsat yo‘q.' }}</p>
        <p v-else-if="loading" role="status">Ma’lumot yuklanmoqda…</p>
        <p v-if="error" class="error-banner" role="alert">{{ error }}<button v-if="!optionsReady && !loading" type="button" class="text-button" @click="load">Qayta urinish</button></p>
        <ul v-if="Object.keys(errors).some(field => field !== 'barcode')" class="error-banner" role="alert"><template v-for="(messages, field) in errors" :key="field"><li v-if="field !== 'barcode'">{{ messages.join(' ') }}</li></template></ul>
        <form v-if="optionsReady && allowed" class="form-stack" @submit.prevent="submit">
            <fieldset :disabled="busy">
                <label>Mahsulot nomi<input v-model="name" required maxlength="255" placeholder="Masalan, Baby Parfume 50 ml"></label>
                <div class="barcode-field-group">
                    <label for="product-barcode">Shtrix-kod</label>
                    <div class="search-field product-barcode-input">
                        <input id="product-barcode" ref="barcodeInput" v-model="barcode" required inputmode="numeric" pattern="[0-9]+" maxlength="100" autocomplete="off" placeholder="Kodni kiriting yoki skanerlang" :aria-invalid="!!errors.barcode" :aria-describedby="errors.barcode ? 'barcode-error' : undefined" @input="scanned = false">
                        <button type="button" class="barcode-scan-button" aria-label="Shtrix-kodni kamera bilan skanerlash" :disabled="generating" @click="cameraOpen = true"><Icon name="scan" /></button>
                    </div>
                    <small v-if="errors.barcode" id="barcode-error" class="field-error">{{ errors.barcode[0] }}</small>
                    <small v-if="scanned" class="barcode-captured" role="status"><Icon name="check" />Kod inputga yozildi</small>
                    <button v-if="!editing" type="button" class="barcode-generate" :disabled="generating" @click="generate"><Icon name="refresh" />{{ generating ? 'Yaratilmoqda…' : 'EAN-13 kod yaratish' }}</button>
                </div>
                <label>Turi<select v-model="type"><option value="package">Paketli</option><option value="size">Razmerli</option></select></label>
                <p v-if="editing && type !== originalType" class="error-banner">Tur almashtirilganda eski qoldiqlar va razmerlar almashtiriladi. Yangi miqdorlarni tekshiring.</p>
                <label>Kelgan narxi<span class="debt-amount-input"><input v-model="initialPrice" required type="number" min="0" max="999999999999" step="1" inputmode="numeric" placeholder="Masalan, 20000"><span>so‘m</span></span></label>
                <label>Sotish narxi<span class="debt-amount-input"><input v-model="price" required type="number" min="0" max="999999999999" step="1" inputmode="numeric" placeholder="Masalan, 25000"><span>so‘m</span></span></label>
                <section v-if="type === 'package'" class="product-stock-fields">
                    <h2>Ombordagi miqdor</h2>
                    <p v-if="!stocks.length">Bu do‘konda ombor yo‘q. Mahsulot qoldiqsiz saqlanadi.</p>
                    <label v-for="(stock, index) in stocks" :key="stock.id">{{ stock.name }}<input v-model="quantities[index].quantity" required type="number" min="0" max="2147483647" step="1" inputmode="numeric"></label>
                </section>
                <section v-else class="product-stock-fields">
                    <h2>Razmerlar va miqdor</h2>
                    <p v-if="!stocks.length">Bu do‘konda ombor yo‘q. Razmerlar qoldiqsiz saqlanadi.</p>
                    <div v-for="(row, index) in sizes" :key="row.key" class="product-size-row">
                        <div class="product-size-heading"><strong>{{ index + 1 }}-razmer</strong><button v-if="sizes.length > 1" type="button" class="text-button" :aria-label="`${index + 1}-razmerni olib tashlash`" @click="removeSize(index)">Olib tashlash</button></div>
                        <label>Razmer<input v-model="row.size" required type="number" min="0" step="any" inputmode="decimal" placeholder="Masalan, 38"></label>
                        <label v-for="(stock, stockIndex) in stocks" :key="stock.id">{{ stock.name }}<input v-model="row.stocks[stockIndex].quantity" required type="number" min="0" max="2147483647" step="1" inputmode="numeric"></label>
                    </div>
                    <button type="button" class="secondary" :disabled="sizes.length >= 50" @click="addSize"><Icon name="plus" />Razmer qo‘shish</button>
                </section>
                <button class="primary" :disabled="busy || generating"><Icon :name="editing ? 'check' : 'plus'" />{{ busy ? 'Saqlanmoqda…' : editing ? 'O‘zgarishlarni saqlash' : 'Mahsulotni saqlash' }}</button>
            </fieldset>
        </form>
        <BarcodeCapture v-if="cameraOpen" @scanned="capture" @close="cameraOpen = false" />
    </main>
</template>
