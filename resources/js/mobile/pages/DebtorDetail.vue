<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api, money, dateLabel } from '../api';
import type { Debtor, Page, Transaction } from '../types';
import Icon from '../Icon.vue';
import '../../photo-picker';

const route = useRoute();
const debtor = ref<Debtor>();
const history = ref<Page<Transaction>>({ data: [] });
const busy = ref(false);
const error = ref('');
const page = ref(1);
const controller = new AbortController();
onBeforeUnmount(() => controller.abort());
const lastPage = computed(() => history.value.last_page ?? 1);
async function load() {
    busy.value=true;error.value='';
    try {
        const [details, transactions] = await Promise.all([
            api<{data:Debtor}>(`debtors/${route.params.id}`, { signal:controller.signal }),
            api<Page<Transaction>>(`debtors/${route.params.id}/transactions?page=${page.value}`, { signal:controller.signal }),
        ]);
        debtor.value=details.data;history.value=transactions;
    } catch(reason) { if(!controller.signal.aborted) error.value=(reason as Error).message; }
    finally { busy.value=false; }
}
function turn(delta:number) {page.value+=delta;void load();}
void load();
</script>
<template>
    <main class="page debtors-page">
        <header class="debtor-detail-toolbar"><RouterLink class="back-link" to="/debtors"><Icon name="back" />Qarzdorlar</RouterLink><RouterLink v-if="debtor?.can_update" class="debtor-edit-action" :to="`/debtors/${debtor.id}/edit`" aria-label="Qarzdorni tahrirlash" title="Qarzdorni tahrirlash"><Icon name="edit" /><span class="sr-only">Tahrirlash</span></RouterLink></header>
        <p v-if="error" class="error-banner" role="alert">{{ error }} <button class="text-button" @click="load">Qayta urinish</button></p>
        <p v-if="busy" class="loading-line" role="status">Yuklanmoqda…</p>
        <template v-if="debtor">
            <div class="heading debtor-profile-heading">
                <div class="debtor-profile-info"><h1>{{ debtor.full_name }}</h1><a v-if="debtor.phone" class="phone-link" :href="`tel:${debtor.phone}`">{{ debtor.phone }}</a></div>
                <debtor-photo-viewer v-if="debtor.photo_url" :key="debtor.photo_url" class="debtor-profile-photo" :src="debtor.photo_url" />
            </div>
            <section class="summary debt-balance"><small>Qolgan qarz</small><strong class="amount">{{ money(debtor.amount,debtor.currency) }}</strong><small>Qarz qayd etilgan: {{ dateLabel(debtor.date) }}</small><span v-if="debtor.overdue" class="badge overdue">20+ kun</span></section>
            <div v-if="debtor.can_update" class="detail-actions"><RouterLink v-if="debtor.amount>0" class="primary" :to="`/debtors/${debtor.id}/payment`"><Icon name="minus" />To‘lov qabul qilish</RouterLink><RouterLink class="secondary" :to="`/debtors/${debtor.id}/debt`"><Icon name="plus" />Qarz qo‘shish</RouterLink></div>
            <p v-if="debtor.note" class="note">{{ debtor.note }}</p>
            <h2 class="section-title">Operatsiyalar tarixi</h2>
            <div class="debt-history" :aria-busy="busy"><article v-for="transaction in history.data" :key="transaction.id" class="transaction-row" :class="{ 'is-payment':transaction.type==='payment' }"><span class="transaction-icon"><Icon :name="transaction.type==='payment'?'down':'plus'" /></span><div class="transaction-content"><div class="transaction-heading"><strong>{{ transaction.type==='payment'?'To‘lov qabul qilindi':'Qarz qo‘shildi' }}</strong><strong class="history-amount">{{ transaction.type==='debt'?'+':'−' }}{{ money(transaction.amount,debtor.currency) }}</strong></div><p class="transaction-meta"><time>{{ dateLabel(transaction.date) }}</time><template v-if="transaction.note"><span aria-hidden="true"> · </span>{{ transaction.note }}</template></p></div></article></div>
            <p v-if="!busy && !history.data.length" class="empty">Hozircha operatsiya yo‘q.</p><div v-if="lastPage>1" class="pagination"><button class="secondary" :disabled="busy||page===1" @click="turn(-1)">Oldingi</button><span>{{ page }} / {{ lastPage }}</span><button class="secondary" :disabled="busy||page>=lastPage" @click="turn(1)">Keyingi</button></div>
        </template>
    </main>
</template>

<style scoped>
.debtor-detail-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
.debtor-detail-toolbar .back-link { margin-bottom: 0; }
.debtor-edit-action { display: grid; place-items: center; width: 48px; min-height: 48px; flex-shrink: 0; border-radius: 14px; color: var(--mobile-accent); background: var(--mobile-tint); border: 1px solid var(--mobile-line); }
.debtor-edit-action:focus-visible { outline: 2px solid var(--mobile-accent); outline-offset: 3px; }
.debtor-edit-action:active { background: var(--mobile-soft); }
.debtor-edit-action svg { width: 22px; height: 22px; }
</style>
