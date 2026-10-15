<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { usePaged } from '../usePaged';
import { money } from '../api';
import { session } from '../session';
import type { Debtor } from '../types';
import Icon from '../Icon.vue';

const route = useRoute();
const query = ref('');
const filter = ref('active');
const filterContainer = ref<HTMLDivElement>();
let filterResizeObserver: ResizeObserver | undefined;
function revealActiveFilter() {
    const container = filterContainer.value;
    const active = container?.querySelector<HTMLButtonElement>('[aria-pressed="true"]');
    if (!container || !active) return;
    const containerBounds = container.getBoundingClientRect();
    const activeBounds = active.getBoundingClientRect();
    const offset = activeBounds.left < containerBounds.left + 8
        ? activeBounds.left - containerBounds.left - 8
        : Math.max(0, activeBounds.right - containerBounds.right + 8);
    if (offset) container.scrollBy({ left: offset });
}
const filters = [
    { value: 'active', label: 'Qarzdorlar' },
    { value: 'closed', label: 'Qarzdorlik yopilganlar' },
    { value: 'overdue', label: 'Muddati o‘tgan qarzdorlar' },
] as const;
const selecting = computed(() => route.query.select === 'debt');
const { result, busy, error, page, load } = usePaged<Debtor>('debtors', query, filter);
const lastPage = computed(() => result.value.meta?.last_page ?? 1);
watch([filter, () => result.value.counts], revealActiveFilter, { flush: 'post' });
onMounted(() => {
    filterResizeObserver = new ResizeObserver(revealActiveFilter);
    if (filterContainer.value) filterResizeObserver.observe(filterContainer.value);
});
onBeforeUnmount(() => filterResizeObserver?.disconnect());
function turn(delta: number) { page.value += delta; void load(); }
</script>
<template>
    <main class="page debtors-page">
        <div class="heading"><h1>{{ selecting ? 'Kimga qarz qo‘shamiz?' : 'Qarzdorlar' }}</h1><p>{{ selecting ? 'Mavjud mijozni tanlang. Yangi profil ochilmaydi.' : 'Kimda qancha qarz — hammasi bir joyda.' }}</p></div>
        <div v-if="!selecting && session.data?.permissions.create_debtor" class="action-stack"><RouterLink class="primary" to="/debtors/new"><Icon name="plus" />Yangi qarzdor</RouterLink></div>
        <RouterLink v-if="selecting" class="back-link" to="/debtors"><Icon name="back" />Ro‘yxatga qaytish</RouterLink>
        <section v-for="total in result.summary" :key="total.currency" class="summary debt-total"><div><h2>Jami qarzdorlik</h2><small>{{ total.count }} ta mijoz</small></div><strong class="amount">{{ money(total.amount, total.currency) }}</strong></section>
        <label class="search-field"><Icon name="search" /><input v-model="query" maxlength="100" aria-label="Qarzdor qidirish" placeholder="Ism yoki telefon raqami" type="search"></label>
        <div ref="filterContainer" class="chips debt-filters" role="group" aria-label="Qarzdorlar filtri"><button v-for="option in filters" :key="option.value" :aria-pressed="filter===option.value" @click="filter=option.value"><span>{{ option.label }}</span><span v-if="result.counts" class="filter-count" :class="{ 'is-overdue':option.value==='overdue' }">{{ result.counts[option.value] }}</span></button></div>
        <p v-if="error" class="error-banner" role="alert">{{ error }} <button class="text-button" @click="load(true)">Qayta urinish</button></p>
        <p v-if="busy" class="loading-line" role="status">Qarzdorlar yuklanmoqda…</p>
        <div class="list-stack" :aria-busy="busy"><RouterLink v-for="debtor in result.data" :key="debtor.id" class="person-card debt-customer" :to="selecting && debtor.can_update ? `/debtors/${debtor.id}/debt` : `/debtors/${debtor.id}`"><div class="person-top"><div class="row-info"><strong>{{ debtor.full_name }}</strong><small>{{ debtor.phone || 'Telefon kiritilmagan' }}</small></div><Icon name="next" /></div><div class="person-bottom"><div class="customer-balance"><small>Qolgan qarz</small><strong>{{ money(debtor.amount,debtor.currency) }}</strong></div><span class="badge" :class="{ overdue:debtor.overdue }">{{ debtor.amount===0 ? 'Qarz yopilgan' : debtor.overdue ? '20+ kun' : 'Qarzi bor' }}</span></div></RouterLink></div>
        <div v-if="!busy && !error && !result.data.length" class="empty"><Icon name="users" /><p>Bu qidiruv bo‘yicha qarzdor topilmadi.</p></div>
        <div v-if="lastPage>1" class="pagination"><button class="secondary" :disabled="busy || page===1" @click="turn(-1)">Oldingi</button><span>{{ page }} / {{ lastPage }}</span><button class="secondary" :disabled="busy || page>=lastPage" @click="turn(1)">Keyingi</button></div>
        <button v-if="result.data.length" class="debt-refresh" :disabled="busy" @click="load(true)"><Icon name="refresh" />{{ busy ? 'Yangilanmoqda…' : 'Yangilash' }}</button>
    </main>
</template>
