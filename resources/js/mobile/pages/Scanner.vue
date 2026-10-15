<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { useRouter } from 'vue-router';
import { startBarcodeScanner, type CameraScanner } from '../barcodeScanner';
import { api, ApiError } from '../api';
import { session } from '../session';
import type { Product } from '../types';
import Icon from '../Icon.vue';

const router=useRouter();
const video=ref<HTMLVideoElement>();
const barcode=ref('');
const camera=ref(false);
const starting=ref(false);
const busy=ref(false);
const error=ref('');
const errorTitle=ref('');
const manualOpen=ref(false);
const manualInput=ref<HTMLInputElement>();
const storeName=computed(()=>session.data?.stores.find(store=>store.id===session.data?.store_id)?.name);
async function openManual(){manualOpen.value=!manualOpen.value;if(manualOpen.value){await nextTick();manualInput.value?.focus();}}
let controls:CameraScanner|undefined;
let cameraController:AbortController|undefined;
let generation=0;
const controller=new AbortController();
function stop(){generation++;cameraController?.abort();cameraController=undefined;controls?.stop();controls=undefined;camera.value=false;starting.value=false;}
async function lookup(code=barcode.value){
    if(!code.trim()||busy.value)return;
    stop();busy.value=true;error.value='';barcode.value=code;
    try{const result=await api<{data:Product}>(`products/lookup?${new URLSearchParams({barcode:code.trim()})}`,{signal:controller.signal});await router.push({path:`/products/${result.data.id}`,state:{scannerSuccess:true}});}
    catch(reason){if(!controller.signal.aborted){errorTitle.value=reason instanceof ApiError&&reason.status===404?'Mahsulot topilmadi':reason instanceof ApiError&&reason.status===0?'Aloqani tekshiring':'Qidirish amalga oshmadi';error.value=reason instanceof ApiError&&reason.status===404?'Kodni qayta skanerlang yoki qo‘lda kiriting.':reason instanceof ApiError&&reason.status===0?'Internet aloqasini tekshirib, qayta urinib ko‘ring.':(reason as Error).message;manualOpen.value=true;}}
    finally{busy.value=false;}
}
async function start(){
    if(starting.value||camera.value)return;
    error.value='';
    errorTitle.value='Kamerani tekshiring';
    if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia){error.value='Kamera uchun saytni HTTPS orqali oching. Hozir kodni qo‘lda kiritishingiz mumkin.';return;}
    starting.value=true;camera.value=true;const version=++generation;
    cameraController=new AbortController();
    try{
        await nextTick();
        if(version!==generation)return;
        const active=await startBarcodeScanner(video.value!,code=>{if(version===generation)void lookup(code);},cameraController.signal);
        if(version!==generation){active.stop();return;}
        controls=active;starting.value=false;
    }catch(reason){if(version!==generation)return;stop();const name=(reason as Error).name;error.value=name==='NotAllowedError'?'Kameraga ruxsat berilmadi. Brauzer sozlamasidan ruxsat bering.':name==='NotFoundError'?'Kamera topilmadi. Kodni qo‘lda kiriting.':'Kamerani ochib bo‘lmadi. Boshqa dastur kameradan foydalanmayotganini tekshiring.';}
}
const visibility=()=>{if(document.hidden)stop();};
document.addEventListener('visibilitychange',visibility);
onBeforeUnmount(()=>{stop();controller.abort();document.removeEventListener('visibilitychange',visibility);});
</script>
<template>
    <main class="page scanner-page">
        <div class="heading scanner-heading">
            <h1>Mahsulotni skanerlang</h1>
            <p>Shtrix-kodni kameraga tuting</p>
        </div>

        <section class="scanner-workspace" aria-label="Barcode skaner">
            <div class="camera-box" :class="{ 'camera-is-active': camera, 'camera-is-busy': busy }" :aria-busy="starting || busy">
                <video ref="video" v-show="camera" muted playsinline autoplay aria-label="Shtrix-kod skaner kamerasi" @click="controls?.focus()"></video>
                <div class="scan-target" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                <div v-if="!camera && !busy" class="camera-placeholder"><Icon name="camera" /><span>Kamerani yoqing</span></div>
                <div v-if="starting || busy" class="scanner-progress" role="status"><span class="scanner-spinner" aria-hidden="true"></span><span>{{ busy ? 'Qidirilmoqda…' : 'Kamera ochilmoqda…' }}</span></div>
                <span v-else class="camera-label">Kodni ramkaga tuting</span>
            </div>
            <p v-if="camera && !starting" class="scanner-live-hint" role="status">Kodni tiniq tuting. Xira bo‘lsa, biroz uzoqlashtiring yoki fokus uchun tasvirga teging.</p>
            <button class="primary scanner-action" :disabled="busy || starting" @click="camera ? stop() : start()">
                <span v-if="busy || starting" class="scanner-spinner" aria-hidden="true"></span><Icon v-else :name="camera ? 'stop' : 'camera'" />
                {{ busy ? 'Qidirilmoqda…' : starting ? 'Kamera ochilmoqda…' : camera ? 'Kamerani to‘xtatish' : 'Kamerani yoqish' }}
            </button>
            <button v-if="starting" class="text-button scanner-cancel" @click="stop">Bekor qilish</button>
        </section>

        <div v-if="error" class="scanner-error" role="alert">
            <Icon name="info" /><div><strong>{{ errorTitle }}</strong><p>{{ error }}</p></div>
        </div>

        <section class="scanner-manual" aria-label="Kodni qo‘lda qidirish">
            <div class="manual-prompt"><span>Barcode topilmadimi?</span><button class="manual-toggle" :aria-expanded="manualOpen" aria-controls="manual-barcode-form" @click="openManual">Kodni qo‘lda kiriting<Icon name="chevron" :class="{ 'is-expanded': manualOpen }" /></button></div>
            <form v-if="manualOpen" id="manual-barcode-form" @submit.prevent="lookup()">
                <label class="sr-only" for="manual-barcode">Shtrix-kodni kiriting</label>
                <div class="search-field scanner-input"><Icon name="scan" /><input id="manual-barcode" ref="manualInput" v-model="barcode" type="text" inputmode="numeric" enterkeyhint="search" required maxlength="100" placeholder="Shtrix-kodni kiriting" autocomplete="off" autocapitalize="off" :spellcheck="false" :disabled="busy"><button type="submit" class="manual-submit" aria-label="Mahsulotni topish" :disabled="busy || !barcode.trim()"><span v-if="busy" class="scanner-spinner" aria-hidden="true"></span><Icon v-else name="next" /></button></div>
            </form>
        </section>
        <p class="scanner-store-info"><Icon name="info" /><span>{{ storeName ? `Faqat ${storeName} do‘konidagi mahsulotlar qidiriladi` : 'Faqat tanlangan do‘kondagi mahsulotlar qidiriladi' }}</span></p>
    </main>
</template>
