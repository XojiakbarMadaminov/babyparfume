<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, clearCache, money, requestId } from '../api';
import { session } from '../session';
import type { Debtor } from '../types';
import Icon from '../Icon.vue';

const route=useRoute();
const router=useRouter();
const mode=route.meta.mode as 'new'|'debt'|'payment';
const isNew=mode==='new';
const payment=mode==='payment';
const title=isNew?'Yangi qarzdor':payment?'To‘lov qabul qilish':'Qarz qo‘shish';
const debtor=ref<Debtor>();
const fullName=ref('');
const phone=ref('');
const amount=ref<number|string>('');
const note=ref('');
const today=new Date();
const localDate=`${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
const date=ref(localDate);
const busy=ref(false);
const loading=ref(!isNew);
const error=ref('');
const storeId=session.data!.store_id;
const controller=new AbortController();
let signature='';
let token='';
onBeforeUnmount(()=>controller.abort());
const remaining=computed(()=>Math.max(0,(debtor.value?.amount??0)+(payment?-1:1)*(Number(amount.value)||0)));
async function load(){
    loading.value=true;error.value='';
    try{debtor.value=(await api<{data:Debtor}>(`debtors/${route.params.id}`,{signal:controller.signal})).data;}
    catch(reason){if(!controller.signal.aborted) error.value=(reason as Error).message;}
    finally{loading.value=false;}
}
if(!isNew) void load();
async function submit(){
    if(busy.value)return;
    busy.value=true;error.value='';
    const payload=isNew?{store_id:storeId,full_name:fullName.value,phone:phone.value,amount:Number(amount.value),date:date.value,note:note.value}:{store_id:storeId,type:mode,amount:Number(amount.value),note:note.value};
    const nextSignature=JSON.stringify(payload);
    if(nextSignature!==signature){token=requestId();signature=nextSignature;}
    try{
        const result=await api<{data:Debtor}>(isNew?'debtors':`debtors/${route.params.id}/transactions`,{body:{...payload,request_id:token}});
        clearCache();session.notice=isNew?'Yangi qarzdor qo‘shildi.':payment?'To‘lov qabul qilindi.':'Qo‘shimcha qarz saqlandi.';
        await router.replace(`/debtors/${result.data.id}`);
    }catch(reason){error.value=(reason as Error).message;}
    finally{busy.value=false;}
}
</script>
<template>
    <main class="page debtors-page debt-form-page"><RouterLink class="back-link" :to="isNew?'/debtors':`/debtors/${route.params.id}`"><Icon name="back" />Orqaga</RouterLink><div class="heading"><h1>{{ title }}</h1><p>{{ isNew?'Mijoz va boshlang‘ich qarzini kiriting.':debtor?.full_name }}</p></div>
        <p v-if="loading" role="status">Ma’lumot yuklanmoqda…</p><p v-if="error" class="error-banner" role="alert">{{ error }} <button v-if="!isNew && !debtor" class="text-button" @click="load">Qayta urinish</button></p>
        <p v-if="!loading && !isNew && debtor && !debtor.can_update" class="empty">Qarzni o‘zgartirish uchun ruxsat yo‘q.</p>
        <form v-if="!loading && (isNew?session.data?.permissions.create_debtor:debtor?.can_update)" class="form-stack" @submit.prevent="submit">
            <fieldset :disabled="busy">
                <section v-if="debtor" class="summary"><small>Hozirgi qarz</small><strong class="amount">{{ money(debtor.amount,debtor.currency) }}</strong></section>
                <template v-if="isNew"><label>Ism va familiya<input v-model="fullName" required maxlength="255" autocomplete="name" placeholder="Masalan, Anvar Karimov"></label><label>Telefon raqami<input v-model="phone" required maxlength="24" type="tel" inputmode="tel" autocomplete="tel" placeholder="+998 90 123 45 67"></label></template>
                <label>{{ payment?'To‘lov summasi':isNew?'Boshlang‘ich qarz':'Qo‘shiladigan qarz' }}<span class="debt-amount-input"><input v-model="amount" type="number" required min="1" :max="payment?debtor?.amount:999999999999" step="1" inputmode="numeric" placeholder="Masalan, 250000"><span>{{ !debtor || debtor.currency==='UZS' ? 'so‘m' : debtor.currency }}</span></span><small v-if="Number(amount)>0" class="amount-preview">{{ money(Number(amount),debtor?.currency) }}</small></label>
                <label v-if="isNew">Qarz sanasi<input v-model="date" type="date" :max="localDate" required><small>Qarz qayd etilgan sana. Qaytarish muddati emas.</small></label>
                <label><span>Izoh <span class="muted">· ixtiyoriy</span></span><textarea v-model="note" rows="3" maxlength="500" placeholder="Masalan, mahsulot nomi yoki kelishuv"></textarea></label>
                <div v-if="!isNew" class="projected"><span>{{ payment?'Qolgan qarz':'Jami qarz bo‘ladi' }}</span><strong aria-live="polite">{{ money(remaining,debtor?.currency) }}</strong></div>
                <button class="primary" :disabled="busy"><Icon :name="payment?'check':'plus'" />{{ busy?'Saqlanmoqda…':payment?'To‘lovni tasdiqlash':isNew?'Qarzdorni qo‘shish':'Qarzni qo‘shish' }}</button>
            </fieldset><p class="footnote">Faqat server tasdiqlagandan keyin saqlangan hisoblanadi.</p>
        </form>
    </main>
</template>
