import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import { base } from './api';
import './mobile.css';

const router = createRouter({
    history: createWebHistory(`${new URL(base).pathname}/`),
    routes: [
        { path: '/', redirect: '/sales' },
        { path: '/sales', component: () => import('./pages/Sales.vue') },
        { path: '/sales/statistics', component: () => import('./pages/SalesStatisticsPage.vue') },
        { path: '/scan', component: () => import('./pages/Scanner.vue') },
        { path: '/products', component: () => import('./pages/Products.vue') },
        { path: '/products/new', component: () => import('./pages/ProductForm.vue') },
        { path: '/products/:id/edit', component: () => import('./pages/ProductForm.vue') },
        { path: '/products/:id', component: () => import('./pages/ProductDetail.vue') },
        { path: '/debtors', component: () => import('./pages/Debtors.vue') },
        { path: '/debtors/new', component: () => import('./pages/DebtForm.vue'), meta: { mode: 'new' } },
        { path: '/debtors/:id/edit', component: () => import('./pages/DebtForm.vue'), meta: { mode: 'edit' } },
        { path: '/debtors/:id', component: () => import('./pages/DebtorDetail.vue') },
        { path: '/debtors/:id/debt', component: () => import('./pages/DebtForm.vue'), meta: { mode: 'debt' } },
        { path: '/debtors/:id/payment', component: () => import('./pages/DebtForm.vue'), meta: { mode: 'payment' } },
        { path: '/:pathMatch(.*)*', redirect: '/sales' },
    ],
    scrollBehavior: () => ({ top: 0 }),
});
createApp(App).use(router).mount('#mobile-app');
