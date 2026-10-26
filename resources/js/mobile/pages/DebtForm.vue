<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, clearCache, money, requestId } from '../api';
import { session } from '../session';
import type { Debtor } from '../types';
import Icon from '../Icon.vue';
import '../../photo-picker';

const route=useRoute();
const router=useRouter();
const mode=route.meta.mode as 'new'|'debt'|'payment'|'edit';
const isNew=mode==='new';
const isEdit=mode==='edit';
const isDebtorForm=isNew||isEdit;
const payment=mode==='payment';
const title=isNew?'Yangi qarzdor':isEdit?'Qarzdorni tahrirlash':payment?'To‘lov qabul qilish':'Qarz qo‘shish';
const debtor=ref<Debtor>();
const fullName=ref('');
const phone=ref(isNew?'+998':'');
const amount=ref<number|string>('');
const note=ref('');
const photo=ref<File|null>(null);
const removePhoto=ref(false);
let photoVersion=0;
function selectPhoto(event: Event): void {
    photo.value=(event as CustomEvent<File|null>).detail;
    photoVersion++;
    if(photo.value) removePhoto.value=false;
}
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
    try{
        debtor.value=(await api<{data:Debtor}>(`debtors/${route.params.id}`,{signal:controller.signal})).data;
        if(isEdit && debtor.value){
            fullName.value=debtor.value.full_name;
            phone.value=debtor.value.phone||'';
            amount.value=debtor.value.amount;
            date.value=debtor.value.initial_debt_date||debtor.value.date||localDate;
            note.value=debtor.value.note||'';
        }
    }
    catch(reason){if(!controller.signal.aborted) error.value=(reason as Error).message;}
    finally{loading.value=false;}
}
if(!isNew) void load();
async function submit(){
    if(busy.value)return;
    busy.value=true;error.value='';
    if(isEdit){
        try{
            const form=new FormData();
            form.append('store_id',String(storeId));
            form.append('full_name',fullName.value);
            form.append('phone',phone.value);
            form.append('amount',String(amount.value));
            form.append('date',date.value);
            form.append('note',note.value);
            if(removePhoto.value && !photo.value){
                form.append('remove_photo','1');
            }
            if(photo.value){
                form.append('photo',photo.value);
            }
            const result=await api<{data:Debtor}>(`debtors/${route.params.id}`,{method:'POST',body:form});
            clearCache();
            session.notice='Qarzdor ma‘lumotlari yangilandi.';
            await router.replace(`/debtors/${result.data.id}`);
        }catch(reason){
            error.value=(reason as Error).message;
        }finally{
            busy.value=false;
        }
        return;
    }
    const payload=isNew?{store_id:storeId,full_name:fullName.value,phone:phone.value,amount:Number(amount.value),date:date.value,note:note.value}:{store_id:storeId,type:mode,amount:Number(amount.value),note:note.value};
    const nextSignature=JSON.stringify({...payload,photoVersion});
    if(nextSignature!==signature){token=requestId();signature=nextSignature;}
    try{
        let body: unknown={...payload,request_id:token};
        if(isNew && photo.value){
            const form=new FormData();
            Object.entries({...payload,request_id:token}).forEach(([key,value])=>form.append(key,String(value)));
            form.append('photo',photo.value);
            body=form;
        }
        const result=await api<{data:Debtor}>(isNew?'debtors':`debtors/${route.params.id}/transactions`,{body});
        clearCache();session.notice=isNew?'Yangi qarzdor qo‘shildi.':payment?'To‘lov qabul qilindi.':'Qo‘shimcha qarz saqlandi.';
        await router.replace(`/debtors/${result.data.id}`);
    }catch(reason){error.value=(reason as Error).message;}
    finally{busy.value=false;}
}
</script>
<template>
    <main class="page debtors-page debt-form-page"><RouterLink class="back-link" :to="isNew?'/debtors':`/debtors/${route.params.id}`"><Icon name="back" />Orqaga</RouterLink><div class="heading"><h1>{{ title }}</h1><p>{{ isNew?'Mijoz va boshlang‘ich qarzini kiriting.':isEdit?'Mijoz ma’lumotlarini tahrirlash':debtor?.full_name }}</p></div>
        <p v-if="loading" role="status">Ma’lumot yuklanmoqda…</p><p v-if="error" class="error-banner" role="alert">{{ error }} <button v-if="!isNew && !debtor" class="text-button" @click="load">Qayta urinish</button></p>
        <p v-if="!loading && !isNew && debtor && !debtor.can_update" class="empty">Qarzni o‘zgartirish uchun ruxsat yo‘q.</p>
        <form v-if="!loading && (isNew?session.data?.permissions.create_debtor:debtor?.can_update)" class="form-stack" @submit.prevent="submit">
            <fieldset :disabled="busy">
                <section v-if="debtor && !isEdit" class="summary"><small>Hozirgi qarz</small><strong class="amount">{{ money(debtor.amount,debtor.currency) }}</strong></section>
                <template v-if="isDebtorForm"><label>Ism va familiya<input v-model="fullName" required maxlength="255" autocomplete="name" placeholder="Masalan, Anvar Karimov"></label><label>Telefon raqami<input v-model="phone" required maxlength="24" type="tel" inputmode="tel" autocomplete="tel" placeholder="+998 90 123 45 67"></label></template>
                <label>{{ payment?'To‘lov summasi':isNew?'Boshlang‘ich qarz':isEdit?'Qarz summasi':'Qo‘shiladigan qarz' }}<span class="debt-amount-input"><input v-model="amount" type="number" :required="!isEdit || Number(amount)>0" min="0" :max="payment?debtor?.amount:999999999999" step="1" inputmode="numeric" placeholder="Masalan, 250000"><span>{{ !debtor || debtor.currency==='UZS' ? 'so‘m' : debtor.currency }}</span></span><small v-if="Number(amount)>0" class="amount-preview">{{ money(Number(amount),debtor?.currency) }}</small></label>
                <label v-if="isDebtorForm">Qarz sanasi<input v-model="date" type="date" :max="localDate" required><small>Qarz qayd etilgan sana. Qaytarish muddati emas.</small></label>
                <label><span>Izoh <span class="muted">· ixtiyoriy</span></span><textarea v-model="note" rows="3" maxlength="500" placeholder="Masalan, mahsulot nomi yoki kelishuv"></textarea></label>
                <section v-if="isDebtorForm" :inert="busy">
                    <h2 class="section-title">Qarzdor rasmi <span class="muted">· ixtiyoriy</span></h2>
                    <div v-if="isEdit && debtor?.photo_url && !removePhoto && !photo" class="existing-photo-box">
                        <img :src="debtor.photo_url" alt="Qarzdor rasmi" class="existing-photo-thumb">
                        <div class="existing-photo-details">
                            <span class="muted">Mavjud rasm</span>
                            <button type="button" class="text-button text-danger" @click="removePhoto = true">Rasmni olib tashlash</button>
                        </div>
                    </div>
                    <div v-if="isEdit && removePhoto && !photo" class="photo-removed-box">
                        <small class="muted">Mavjud rasm olib tashlanadi.</small>
                        <button type="button" class="text-button" @click="removePhoto = false">Bekor qilish</button>
                    </div>
                    <debtor-photo-picker @photo-selected="selectPhoto" />
                </section>
                <div v-if="!isDebtorForm" class="projected"><span>{{ payment?'Qolgan qarz':'Jami qarz bo‘ladi' }}</span><strong aria-live="polite">{{ money(remaining,debtor?.currency) }}</strong></div>
                <button class="primary" :disabled="busy"><Icon :name="isEdit?'check':payment?'check':'plus'" />{{ busy?'Saqlanmoqda…':isEdit?'O‘zgarishlarni saqlash':payment?'To‘lovni tasdiqlash':isNew?'Qarzdorni qo‘shish':'Qarzni qo‘shish' }}</button>
            </fieldset><p class="footnote">Faqat server tasdiqlagandan keyin saqlangan hisoblanadi.</p>
        </form>
    </main>
</template>

<style scoped>
.existing-photo-box {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 12px;
    padding: 10px 14px;
    border-radius: 14px;
    background: var(--mobile-surface, rgba(255, 255, 255, 0.05));
    border: 1px solid var(--mobile-line, rgba(255, 255, 255, 0.1));
}
.existing-photo-thumb {
    width: 56px;
    height: 56px;
    border-radius: 10px;
    object-fit: cover;
    border: 1px solid var(--mobile-line, rgba(255, 255, 255, 0.15));
}
.existing-photo-details {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.text-danger {
    color: #ef4444;
}
.photo-removed-box {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    padding: 8px 12px;
    border-radius: 10px;
    background: rgba(239, 68, 68, 0.1);
    border: 1px dashed rgba(239, 68, 68, 0.3);
}
</style>
