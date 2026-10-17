import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createMemoryHistory, createRouter } from 'vue-router';

const preview = process.argv.includes('--preview');
const server = await createServer({
    configFile: false,
    logLevel: 'silent',
    plugins: [vue(), ...(preview ? [tailwind()] : [])],
    server: preview ? { host: '0.0.0.0', port: 8196, strictPort: true } : { middlewareMode: true, hmr: false },
    appType: 'custom',
});
const date = new Date();
const today = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const filter = { storeId: 7, period: 'today', start: today, end: today };
const report = {
    filter,
    salesAmount: 12450000, salesCount: 42, averageReceipt: 296428.57,
    profit: 3200000, marginPercent: 25.7, changePercent: 18.4,
    trend: [
        { label: '08:00', amount: 800000 }, { label: '10:00', amount: 1900000 },
        { label: '12:00', amount: 1250000 }, { label: '14:00', amount: 3400000 },
        { label: '16:00', amount: 2100000 }, { label: '18:00', amount: 3000000 },
    ],
    payments: [
        { key: 'cash', label: 'Naqd', amount: 6200000, percent: 49.8 },
        { key: 'card', label: 'Karta', amount: 4000000, percent: 32.1 },
        { key: 'transfer', label: 'O‘tkazma', amount: 1500000, percent: 12.1 },
        { key: 'debt', label: 'Qarz', amount: 750000, percent: 6 },
    ],
    topProducts: [
        { id: 17, name: 'Dior Sauvage', quantity: 8, amount: 3600000 },
        { id: 18, name: 'Versace Eros', quantity: 5, amount: 2100000 },
        { id: 19, name: 'Lattafa Yara', quantity: 4, amount: 1400000 },
    ],
};
const states = {
    ready: { report },
    unavailable: {},
    loading: { loading: true },
    error: { error: 'Statistikani yuklab bo‘lmadi. Qayta urinib ko‘ring.' },
    empty: { report: { ...report, salesAmount: 0, salesCount: 0, averageReceipt: null, profit: 0, marginPercent: null, changePercent: null, trend: [], payments: [], topProducts: [] } },
    restricted: { report: { ...report, profit: null, marginPercent: null } },
    otherStore: { report: { ...report, filter: { ...filter, storeId: 8 } } },
    otherPeriod: { report: { ...report, filter: { ...filter, period: 'week' } } },
    negative: { report: { ...report, changePercent: -8.2, trend: [{ label: '08:00', amount: -500 }, { label: '10:00', amount: 0 }] } },
    singlePoint: { report: { ...report, trend: [{ label: '08:00', amount: 0 }] } },
};

if (preview) {
    server.middlewares.use(async (request, response, next) => {
        const url = new URL(request.url, 'http://localhost:8196');
        if (url.pathname === '/__test/api/session' || url.pathname === '/__test/api/store') {
            let body = '';
            for await (const chunk of request) body += chunk;
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({
                user: { name: 'UI test' }, store_id: body ? JSON.parse(body).store_id : 7,
                stores: [{ id: 7, name: 'Million' }, { id: 8, name: 'Ikkinchi do‘kon' }],
                permissions: { sales: true, products: true, debtors: true },
            }));
            return;
        }
        if (!url.pathname.startsWith('/mobile')) return next();
        const state = states[url.searchParams.get('state')] ?? states.ready;
        const html = `<!doctype html><html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture"><title>Statistika UI testi — haqiqiy hisobot emas</title></head><body><div id="mobile-app" data-api="/__test/api" data-base="http://localhost:8196/mobile"></div><script type="module">
            import { createApp, reactive } from '/node_modules/.vite/deps/vue.js';
            import { createRouter, createWebHistory } from '/node_modules/.vite/deps/vue-router.js';
            import App from '/resources/js/mobile/App.vue';
            import Page from '/resources/js/mobile/pages/SalesStatistics.vue';
            import '/resources/js/mobile/mobile.css';
            const state = reactive(${JSON.stringify(state)});
            const original = ${JSON.stringify(state.report ?? report)};
            const router = createRouter({ history: createWebHistory('/mobile'), routes: [{ path: '/sales/statistics', component: Page, props: () => ({ ...state, onChange: filter => {
                if (!state.report) return;
                state.report = { ...original, filter, trend: original.trend.map((point, index) => ({ ...point, label: filter.period === 'today' ? point.label : filter.period === 'week' ? ['Du', 'Se', 'Ch', 'Pa', 'Ju', 'Sh'][index] : String(index + 1) })) };
            } }) }, { path: '/:pathMatch(.*)*', component: { template: '<p>Test sahifasi</p>' } }] });
            createApp(App).use(router).mount('#mobile-app');
        </script></body></html>`;
        response.setHeader('Content-Type', 'text/html');
        response.end(await server.transformIndexHtml(request.url, html));
    });
    await server.listen();
    process.stdout.write('UI fixture: http://localhost:8196/mobile/sales/statistics (test data only)\n');
} else {
    try {
        const { default: Page } = await server.ssrLoadModule('/resources/js/mobile/pages/SalesStatistics.vue');
        const { session } = await server.ssrLoadModule('/resources/js/mobile/session.ts');
        session.data = { store_id: 7, permissions: { products: true } };
        const output = {};
        for (const [name, props] of Object.entries(states)) {
            const app = createSSRApp(Page, props);
            const router = createRouter({ history: createMemoryHistory('/mobile'), routes: [{ path: '/:pathMatch(.*)*', component: Page }] });
            await router.push('/sales/statistics');
            await router.isReady();
            app.use(router);
            output[name] = await renderToString(app);
        }
        process.stdout.write(JSON.stringify(output));
    } finally {
        await server.close();
    }
}
