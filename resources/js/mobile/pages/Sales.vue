<script setup lang="ts">
import { ref } from 'vue';
import { base } from '../api';
import Icon from '../Icon.vue';
import { session } from '../session';

const loading = ref(true);
const source = `${base}/sales-workspace`;
</script>

<template>
    <main class="sales-host" aria-label="Sotuv">
        <RouterLink v-if="session.data?.permissions.sales_statistics" class="sales-statistics-link" to="/sales/statistics"><Icon name="chart" />Sotuv statistikasi<Icon name="next" /></RouterLink>
        <p v-if="loading" class="sales-loading" role="status">Sotuv yuklanmoqda…</p>
        <iframe :src="source" title="Sotuv" allow="camera 'self'" @load="loading = false"></iframe>
    </main>
</template>

<style scoped>
.sales-host { position: relative; flex: 1; min-height: 0; display: flex; flex-direction: column; }
.sales-host iframe { width: 100%; flex: 1; min-height: 0; border: 0; background: #121316; }
.sales-statistics-link { display: flex; align-items: center; gap: 8px; flex-shrink: 0; min-height: 44px; padding: 8px 16px; border-bottom: 1px solid var(--mobile-line); background: var(--mobile-card); color: var(--mobile-accent); font-size: 12px; }
.sales-statistics-link svg { width: 17px; height: 17px; }
.sales-statistics-link svg:last-child { margin-left: auto; }
.sales-loading { position: absolute; inset: 44px 0 0; z-index: 1; margin: 0; padding: 24px; background: #121316; color: #a5a7b0; }
</style>
