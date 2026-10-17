<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Icon from '../Icon.vue';
import SalesTrendChart from '../SalesTrendChart.vue';
import { session } from '../session';
import { statisticsDate, statisticsMoney, type SalesStatistics, type StatisticsFilter, type StatisticsPeriod } from '../salesStatistics';

const props = withDefaults(defineProps<{ report?: SalesStatistics | null; loading?: boolean; error?: string }>(), { report: null, loading: false, error: '' });
const emit = defineEmits<{ change: [filter: StatisticsFilter]; retry: [] }>();
const today = statisticsDate(new Date());
const period = ref<StatisticsPeriod>('today');
const start = ref(today);
const end = ref(today);
const draftStart = ref(today);
const draftEnd = ref(today);
const rangeError = ref('');
const periods: { value: StatisticsPeriod; label: string }[] = [
    { value: 'today', label: 'Bugun' }, { value: 'week', label: 'Hafta' }, { value: 'month', label: 'Oy' }, { value: 'range', label: 'Oraliq' },
];
const filter = computed<StatisticsFilter>(() => ({ storeId: session.data?.store_id ?? 0, period: period.value, start: start.value, end: end.value }));
const report = computed(() => {
    const source = props.report;
    return source && Object.entries(filter.value).every(([key, value]) => source.filter[key as keyof StatisticsFilter] === value) ? source : null;
});
const awaitingRange = computed(() => period.value === 'range' && (draftStart.value !== start.value || draftEnd.value !== end.value));
const empty = computed(() => report.value?.salesCount === 0 && report.value.salesAmount === 0);
const unavailable = computed(() => !props.loading && !props.error && !report.value);
const rangeLabel = computed(() => start.value === end.value ? formatDate(start.value) : `${formatDate(start.value)} — ${formatDate(end.value)}`);
const changeLabel = computed(() => {
    const change = report.value?.changePercent;
    if (change == null) return 'Oldingi davr bilan taqqoslash mavjud emas';
    return `${change > 0 ? '+' : ''}${new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 }).format(change).replaceAll(',', ' ')}% oldingi davrga nisbatan`;
});
const kpis = computed(() => [
    { label: 'Savdolar soni', value: report.value ? `${report.value.salesCount} ta` : '—', icon: 'cart' },
    { label: 'O‘rtacha chek', value: statisticsMoney(report.value?.averageReceipt), icon: 'receipt' },
    { label: 'Foyda', value: statisticsMoney(report.value?.profit), icon: 'trend', restricted: report.value && report.value.profit === null },
]);
watch(filter, value => emit('change', value), { immediate: true });
function formatDate(value: string): string { return value.split('-').reverse().join('.'); }
function choosePeriod(value: StatisticsPeriod) {
    period.value = value;
    rangeError.value = '';
    if (value === 'range') { draftStart.value = start.value; draftEnd.value = end.value; return; }
    const current = new Date();
    end.value = statisticsDate(current);
    if (value === 'week') current.setDate(current.getDate() - (current.getDay() + 6) % 7);
    if (value === 'month') current.setDate(1);
    start.value = statisticsDate(current);
}
function applyRange() {
    if (!draftStart.value || !draftEnd.value || draftStart.value > draftEnd.value || draftEnd.value > today) {
        rangeError.value = 'Sanalarni tekshiring: boshlanish tugashdan keyin, tugash esa bugundan keyin bo‘lmasin.';
        return;
    }
    start.value = draftStart.value; end.value = draftEnd.value; rangeError.value = '';
}
function barWidth(value: number): string { return `${Math.max(0, Math.min(100, value))}%`; }
</script>

<template>
    <main class="page statistics-page" :aria-busy="loading">
        <RouterLink class="back-link" to="/sales"><Icon name="back" />Savdoga qaytish</RouterLink>
        <div class="heading"><h1>Sotuv statistikasi</h1><p>Savdo natijalarini kuzating va solishtiring.</p></div>
        <div class="chips statistics-periods" role="group" aria-label="Statistika davri"><button v-for="option in periods" :key="option.value" type="button" :aria-pressed="period === option.value" @click="choosePeriod(option.value)">{{ option.label }}</button></div>
        <form v-if="period === 'range'" class="statistics-range" @submit.prevent="applyRange">
            <div class="statistics-date-grid"><label>Boshlanish<input v-model="draftStart" type="date" required :max="draftEnd || today" :aria-invalid="!!rangeError"></label><label>Tugash<input v-model="draftEnd" type="date" required :min="draftStart" :max="today" :aria-invalid="!!rangeError"></label></div>
            <p v-if="rangeError" class="statistics-range-error" role="alert">{{ rangeError }}</p><button class="secondary" type="submit">Oraliqni qo‘llash</button>
        </form>
        <p class="statistics-date-label" aria-live="polite"><Icon name="calendar" />{{ rangeLabel }}<span v-if="awaitingRange"> · Yangi oraliq hali qo‘llanmagan</span></p>
        <p v-if="error" class="error-banner" role="alert">{{ error }} <button class="text-button" type="button" @click="emit('retry')">Qayta urinish</button></p>
        <section v-if="unavailable" class="statistics-notice" role="status"><Icon name="info" /><div><strong>Statistika ma’lumotlari hali ulanmagan</strong><p>Mobil hisobot uchun ma’lumot manbasi mavjud emas. Ko‘rsatkichlar o‘rnida namuna yoki taxminiy summalar berilmaydi.</p></div></section>
        <div v-if="loading" class="statistics-loading" role="status"><span class="sr-only">Sotuv statistikasi yuklanmoqda…</span><div class="summary statistics-hero statistics-skeleton"><span></span><strong></strong><span></span></div><div class="statistics-kpis"><div v-for="index in 3" :key="index" class="summary statistics-kpi statistics-skeleton"><span></span><strong></strong></div></div><div class="summary statistics-chart-skeleton statistics-skeleton"><span></span><div></div></div><div class="summary statistics-skeleton"><span v-for="index in 3" :key="index"></span></div></div>
        <template v-else>
            <section class="summary statistics-hero" aria-labelledby="sales-total-label"><div class="statistics-section-heading"><h2 id="sales-total-label">Jami savdo</h2><span class="statistics-icon"><Icon name="trend" /></span></div><strong class="statistics-total">{{ statisticsMoney(report?.salesAmount) }}</strong><p class="statistics-change" :class="{ 'is-positive': (report?.changePercent ?? 0) > 0, 'is-negative': (report?.changePercent ?? 0) < 0 }"><Icon v-if="report?.changePercent != null" :name="report.changePercent < 0 ? 'down' : 'trend'" />{{ changeLabel }}</p></section>
            <div class="statistics-kpis"><section v-for="kpi in kpis" :key="kpi.label" class="summary statistics-kpi"><div><Icon :name="kpi.icon" /><h2>{{ kpi.label }}</h2></div><strong>{{ kpi.value }}</strong><small v-if="kpi.restricted">Ma’lumot mavjud emas</small></section></div>
            <p v-if="report" class="statistics-caption">Jami savdo va foyda qaytarish hamda almashuvlarni hisobga oladi. Dinamika, to‘lovlar va o‘rtacha chek yakunlangan savdolar bo‘yicha.</p>
            <section v-if="empty" class="empty statistics-empty" role="status"><Icon name="chart" /><h2>Sotuv ma’lumotlari topilmadi</h2><p>Boshqa davrni tanlang yoki keyinroq qayta tekshiring.</p></section>
            <section class="summary statistics-section" aria-labelledby="sales-trend-label"><div class="statistics-section-heading"><h2 id="sales-trend-label">Sotuv dinamikasi</h2><span class="statistics-caption">{{ period === 'today' ? 'Soatlar' : period === 'week' ? 'Kunlar' : 'Sanalar' }}</span></div><SalesTrendChart v-if="report?.trend.length" :points="report.trend" /><div v-else class="statistics-chart-empty"><Icon name="chart" /><p>{{ unavailable ? 'Ma’lumot ulangach dinamika shu yerda ko‘rinadi.' : 'Bu davr uchun dinamika mavjud emas.' }}</p></div></section>
            <section class="summary statistics-section" aria-labelledby="sales-payments-label"><div class="statistics-section-heading"><h2 id="sales-payments-label">To‘lov turlari</h2><Icon name="wallet" /></div><ul v-if="report?.payments.length" class="statistics-payments"><li v-for="payment in report.payments" :key="payment.key"><div class="statistics-payment-heading"><span>{{ payment.label }}</span><strong>{{ statisticsMoney(payment.amount) }}</strong><small>{{ new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(payment.percent) }}%</small></div><div class="statistics-payment-track" role="progressbar" :aria-label="payment.label" :aria-valuenow="Math.max(0, Math.min(100, payment.percent))" aria-valuemin="0" aria-valuemax="100"><span :style="{ width: barWidth(payment.percent) }" :class="`payment-${payment.key}`"></span></div></li></ul><p v-else class="statistics-section-empty">To‘lovlar bo‘yicha ma’lumot mavjud emas.</p></section>
            <section class="statistics-top" aria-labelledby="sales-products-label"><div class="statistics-section-heading"><h2 id="sales-products-label">Top mahsulotlar</h2><span class="statistics-caption">Savdo hajmi bo‘yicha</span></div><ol v-if="report?.topProducts.length" class="list-stack statistics-products"><li v-for="(product, index) in report.topProducts" :key="product.id"><component :is="session.data?.permissions.products ? 'RouterLink' : 'div'" :to="session.data?.permissions.products ? `/products/${product.id}` : undefined" class="product-row statistics-product"><span class="statistics-rank">{{ index + 1 }}</span><span v-if="product.image" class="product-thumb"><img :src="product.image" alt="" loading="lazy" width="40" height="40"></span><div class="row-info"><strong>{{ product.name }}</strong><small>{{ product.quantity }} dona · {{ statisticsMoney(product.amount) }}</small></div><Icon v-if="session.data?.permissions.products" name="next" /></component></li></ol><p v-else class="summary statistics-section-empty">Bu davr uchun mahsulotlar ro‘yxati mavjud emas.</p></section>
        </template>
    </main>
</template>

<style scoped>
.statistics-page { display: flex; flex-direction: column; gap: 16px; }
.statistics-page .back-link, .statistics-page .heading, .statistics-page .chips, .statistics-page .summary { margin: 0; }
.statistics-page .heading { padding-bottom: 4px; }
.statistics-periods { display: flex; gap: 8px; overflow-x: auto; scrollbar-width: none; padding: 4px; margin-inline: -4px; }
.statistics-periods::-webkit-scrollbar { display: none; }
.statistics-periods button { flex: 1 0 auto; min-height: 44px; }
.statistics-date-label { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; font-size: 12px; }
.statistics-date-label svg { width: 16px; height: 16px; }
.statistics-range { display: flex; flex-direction: column; gap: 12px; padding: 16px; border: 1px solid var(--mobile-line); border-radius: 16px; background: var(--mobile-card); }
.statistics-date-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.statistics-date-grid label { display: flex; flex-direction: column; min-width: 0; gap: 8px; font-size: 12px; color: var(--mobile-muted); }
.statistics-date-grid input { width: 100%; min-width: 0; min-height: 52px; font-size: 13px; padding: 10px 8px; }
.statistics-range-error { color: var(--mobile-danger); font-size: 12px; }
.statistics-notice { display: flex; align-items: flex-start; gap: 10px; padding: 14px; background: var(--mobile-tint); border: 1px solid var(--mobile-line); border-radius: 14px; }
.statistics-notice > svg { color: var(--mobile-accent); width: 18px; }
.statistics-notice strong { font-size: 13px; }
.statistics-notice p { font-size: 12px; padding-top: 4px; }
.statistics-hero { gap: 12px; padding: 20px; min-height: 180px; border-top: 2px solid var(--mobile-accent); }
.statistics-section-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.statistics-section-heading > svg { color: var(--mobile-muted); width: 18px; }
.statistics-icon { width: 34px; height: 34px; display: grid; place-items: center; background: var(--mobile-tint); color: var(--mobile-accent); border-radius: 10px; }
.statistics-total { font-size: clamp(24px, 6.8vw, 32px); font-weight: 700; letter-spacing: -.7px; line-height: 1.25; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.statistics-change { display: flex; align-items: center; gap: 6px; font-size: 12px; }
.statistics-change svg { width: 16px; height: 16px; }
.statistics-change.is-positive { color: light-dark(#197253, #7bd4b1); }
.statistics-change.is-negative { color: var(--mobile-danger); }
.statistics-kpis { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.statistics-kpi { padding: 16px 12px; gap: 10px; min-height: 110px; }
.statistics-kpi:last-child { grid-column: 1 / -1; }
.statistics-kpi > div { display: flex; align-items: center; gap: 6px; }
.statistics-kpi svg { width: 15px; height: 15px; color: var(--mobile-muted); }
.statistics-kpi h2 { color: var(--mobile-muted); font-size: 12px; font-weight: 500; }
.statistics-kpi strong { font-size: clamp(16px, 4.4vw, 20px); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; line-height: 1.35; }
.statistics-kpi small { color: var(--mobile-muted); font-size: 11px; }
.statistics-section { gap: 20px; margin-top: 4px !important; }
.statistics-caption { font-size: 11px; color: var(--mobile-muted); text-align: right; }
.statistics-chart-empty { min-height: 216px; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 12px; text-align: center; }
.statistics-chart-empty svg { color: var(--mobile-muted); width: 32px; height: 32px; }
.statistics-chart-empty p, .statistics-section-empty { font-size: 13px; }
.statistics-payments { display: flex; flex-direction: column; gap: 20px; list-style: none; padding: 0; margin: 0; }
.statistics-payment-heading { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 8px; font-size: 13px; padding-bottom: 8px; }
.statistics-payment-heading strong { text-align: right; font-size: 12px; font-weight: 600; overflow-wrap: anywhere; }
.statistics-payment-heading small { grid-column: 2; text-align: right; font-size: 11px; color: var(--mobile-muted); }
.statistics-payment-track { height: 6px; background: var(--mobile-soft); border-radius: 8px; overflow: hidden; }
.statistics-payment-track > span { display: block; height: 100%; border-radius: inherit; background: var(--mobile-accent); }
.statistics-payment-track .payment-card { opacity: .78; }
.statistics-payment-track .payment-transfer { opacity: .58; }
.statistics-payment-track .payment-debt { opacity: .4; }
.statistics-top { display: flex; flex-direction: column; gap: 12px; padding-top: 4px; }
.statistics-products { list-style: none; margin: 0; padding: 0; }
.statistics-product { min-height: 80px; border-radius: 16px; }
.statistics-rank { flex-shrink: 0; width: 24px; height: 28px; display: grid; place-items: center; border-radius: 8px; background: var(--mobile-tint); color: var(--mobile-accent); font-weight: 600; }
.statistics-product .row-info small { overflow-wrap: anywhere; }
.statistics-product .product-thumb { width: 36px; height: 40px; }
.statistics-empty { display: flex; flex-direction: column; align-items: center; gap: 12px; border: 1px solid var(--mobile-line); border-radius: 16px; background: var(--mobile-card); }
.statistics-empty h2 { color: var(--mobile-text); }
.statistics-empty p { font-size: 13px; }
.statistics-loading { display: flex; flex-direction: column; gap: 16px; }
.statistics-skeleton { pointer-events: none; }
.statistics-skeleton span, .statistics-skeleton strong, .statistics-skeleton > div { display: block; background: var(--mobile-soft); border-radius: 6px; animation: statistics-pulse 1.6s ease-in-out infinite; }
.statistics-skeleton span { width: 65%; height: 16px; }
.statistics-skeleton strong { width: 90%; height: 36px; }
.statistics-chart-skeleton > div { height: 216px; width: 100%; margin-top: 16px; }
.statistics-chart-skeleton { min-height: 290px; }
@keyframes statistics-pulse { 50% { opacity: .45; } }
@media (prefers-reduced-motion: reduce) { .statistics-skeleton * { animation: none; } }
</style>
