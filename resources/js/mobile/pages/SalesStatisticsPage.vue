<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { api } from '../api';
import { session } from '../session';
import type { SalesStatistics, StatisticsFilter } from '../salesStatistics';
import SalesStatisticsView from './SalesStatistics.vue';

const report = ref<SalesStatistics | null>(null);
const loading = ref(false);
const error = ref('');
let activeRequest: AbortController | null = null;
let currentFilter: StatisticsFilter | null = null;

async function load(filter: StatisticsFilter): Promise<void> {
    activeRequest?.abort();
    const controller = new AbortController();
    activeRequest = controller;
    currentFilter = filter;
    report.value = null;
    error.value = '';
    if (!session.data?.permissions.sales_statistics) {
        error.value = 'Sotuv statistikasini ko‘rish uchun ruxsat berilmagan.';
        loading.value = false;
        return;
    }
    loading.value = true;
    try {
        const query = new URLSearchParams({ period: filter.period, start: filter.start, end: filter.end });
        const result = await api<{ data: SalesStatistics }>(`sales/statistics?${query}`, { signal: controller.signal });
        if (!controller.signal.aborted) report.value = result.data;
    } catch (reason) {
        if (!controller.signal.aborted) error.value = (reason as Error).message;
    } finally {
        if (activeRequest === controller) loading.value = false;
    }
}
function retry(): void { if (currentFilter) void load(currentFilter); }
onBeforeUnmount(() => activeRequest?.abort());
</script>

<template>
    <SalesStatisticsView :report="report" :loading="loading" :error="error" @change="load" @retry="retry" />
</template>
