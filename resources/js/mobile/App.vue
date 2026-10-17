<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, ApiError, clearCache } from './api';
import { session } from './session';
import type { Session } from './types';
import Icon from './Icon.vue';

const router = useRouter();
const canAccessPage = computed(() => {
    const permissions = session.data?.permissions;
    const path = router.currentRoute.value.path;
    if (!permissions) return false;
    if (/^\/sales(?:\/|$)/.test(path)) return permissions.sales && (path !== '/sales/statistics' || permissions.sales_statistics);
    if (/^\/(scan|products)(?:\/|$)/.test(path)) return permissions.products;
    if (/^\/debtors(?:\/|$)/.test(path)) return permissions.debtors;
    return false;
});
watch(() => router.currentRoute.value.path, () => { void choosePermittedPage(); });
const ready = ref(false);
const error = ref('');
const email = ref('');
const password = ref('');
const online = ref(navigator.onLine);
const updateNetwork = () => { online.value = navigator.onLine; };
const expire = () => { session.data = null; session.notice = ''; clearCache(); };
const reload = () => location.reload();
window.addEventListener('online', updateNetwork);
window.addEventListener('offline', updateNetwork);
window.addEventListener('mobile:unauthenticated', expire);
onBeforeUnmount(() => {
    window.removeEventListener('online', updateNetwork);
    window.removeEventListener('offline', updateNetwork);
    window.removeEventListener('mobile:unauthenticated', expire);
});
async function bootstrap() {
    error.value = '';
    try { session.data = await api<Session>('session'); await choosePermittedPage(); }
    catch (reason) { if (!(reason instanceof ApiError && reason.status === 401)) error.value = (reason as Error).message; }
    finally { ready.value = true; }
}
async function login() {
    session.busy = true; error.value = '';
    try { session.data = await api<Session>('login', { body: { email: email.value, password: password.value } }); password.value = ''; clearCache(); await choosePermittedPage(); }
    catch (reason) { error.value = (reason as Error).message; }
    finally { session.busy = false; }
}
async function switchStore(event: Event) {
    const select = event.target as HTMLSelectElement;
    session.busy = true; error.value = ''; session.notice = ''; clearCache();
    try { session.data = await api<Session>('store', { body: { store_id: Number(select.value) } }); await choosePermittedPage(); }
    catch (reason) { error.value = (reason as Error).message; }
    finally { select.value = String(session.data?.store_id ?? ''); session.busy = false; }
}
async function logout() {
    session.busy = true;
    try { await api('logout', { body: {} }); clearCache(); location.reload(); }
    catch (reason) { error.value = (reason as Error).message; session.busy = false; }
}
async function choosePermittedPage() {
    await router.isReady();
    const permissions = session.data?.permissions;
    if (!permissions) return;
    if (!canAccessPage.value) {
        if (permissions.sales) await router.replace('/sales');
        else if (permissions.products) await router.replace('/products');
        else if (permissions.debtors) await router.replace('/debtors');
    }
}
void bootstrap();
</script>
<template>
    <div class="mobile-shell" :class="{ 'scanner-shell': ['/scan', '/sales', '/sales/statistics'].includes($route.path), 'sales-shell': $route.path === '/sales', 'debtors-shell': /^\/debtors(?:\/|$)/.test($route.path) }">
        <header class="app-header compact-header">
            <div class="header-identity"><div class="brand"><span class="brand-mark">b.</span><div><strong>BABY PARFUME</strong><small>Mobil yordamchi</small></div></div><button v-if="session.data" class="icon-button" aria-label="Hisobdan chiqish" :disabled="session.busy" @click="logout"><Icon name="logout" /></button></div>
            <div v-if="session.data" class="store-bar"><Icon name="store" /><label class="sr-only" for="mobile-store">Do‘konni tanlang</label><select id="mobile-store" :value="session.data.store_id ?? ''" :disabled="session.busy" @change="switchStore"><option value="" disabled>Do‘konni tanlang</option><option v-for="store in session.data.stores" :key="store.id" :value="store.id">{{ store.name }}</option></select></div>
        </header>
        <p v-if="!online" class="network-warning" role="status">Internet yo‘q. Qarz va to‘lovlar faqat aloqa tiklanganda saqlanadi.</p>
        <div v-if="error" class="error-banner" role="alert">{{ error }} <button class="text-button" @click="bootstrap">Qayta tekshirish</button><button class="text-button" @click="reload">Sahifani yangilash</button></div>
        <main v-if="!ready" class="page"><p role="status">Yuklanmoqda…</p></main>
        <main v-else-if="!session.data" class="page login-page"><div class="heading"><h1>Xush kelibsiz</h1><p>Mavjud hisobingiz bilan kiring.</p></div><form class="form-stack" @submit.prevent="login"><label>Email<input v-model="email" type="email" required autocomplete="username" placeholder="Email manzilingiz"></label><label>Parol<input v-model="password" type="password" required autocomplete="current-password" placeholder="Parolingiz"></label><button class="primary" :disabled="session.busy || !online">{{ session.busy ? 'Tekshirilmoqda…' : 'Kirish' }}<Icon name="next" /></button></form></main>
        <template v-else>
            <main v-if="!session.data.store_id" class="page empty"><h1>Do‘konni tanlang</h1><p v-if="session.data.stores.length">Yuqoridagi ro‘yxatdan ishlaydigan do‘koningizni belgilang.</p><p v-else>Hisobingizga do‘kon biriktirilmagan. Administratorga murojaat qiling.</p></main>
            <main v-else-if="session.busy" class="page" role="status">Do‘kon yangilanmoqda…</main>
            <main v-else-if="!canAccessPage" class="page empty"><h1>Kirish uchun ruxsat yo‘q</h1><p>Kerakli bo‘limga ruxsat olish uchun administratorga murojaat qiling.</p></main>
            <template v-else><p v-if="session.notice" class="success-banner" role="status">{{ session.notice }} <button class="text-button" @click="session.notice = ''">Yopish</button></p><RouterView :key="`${session.data.store_id}:${$route.path}`" /></template>
            <nav class="bottom-nav" aria-label="Asosiy navigatsiya"><RouterLink v-if="session.data.permissions.sales" to="/sales" :class="{ 'router-link-active': $route.path === '/sales/statistics' }" :aria-current="$route.path === '/sales/statistics' ? 'page' : undefined"><Icon name="cart" /><span>Savdo</span></RouterLink><RouterLink v-if="session.data.permissions.products" to="/products"><Icon name="box" /><span>Mahsulotlar</span></RouterLink><RouterLink v-if="session.data.permissions.debtors" to="/debtors"><Icon name="users" /><span>Qarzdorlar</span></RouterLink></nav>
        </template>
    </div>
</template>
