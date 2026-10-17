<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { startBarcodeScanner, type CameraScanner } from './barcodeScanner';
import Icon from './Icon.vue';

const emit = defineEmits<{ scanned: [code: string]; close: [] }>();
const dialog = ref<HTMLDialogElement>();
const video = ref<HTMLVideoElement>();
const starting = ref(false);
const error = ref('');
let controller: AbortController | undefined;
let scanner: CameraScanner | undefined;

function stop() {
    controller?.abort();
    scanner?.stop();
    scanner = undefined;
}
async function start() {
    stop();
    error.value = '';
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        error.value = 'Kamera uchun saytni HTTPS orqali oching. Kodni qo‘lda ham kiritishingiz mumkin.';
        return;
    }
    const current = new AbortController();
    controller = current;
    starting.value = true;
    try {
        const active = await startBarcodeScanner(video.value!, code => {
            if (!current.signal.aborted) emit('scanned', code);
        }, current.signal);
        if (current.signal.aborted) active.stop();
        else scanner = active;
    } catch (reason) {
        if (current.signal.aborted) return;
        const name = (reason as Error).name;
        error.value = name === 'NotAllowedError'
            ? 'Kameraga ruxsat berilmadi. Brauzer sozlamasidan ruxsat bering yoki kodni qo‘lda kiriting.'
            : name === 'NotFoundError'
                ? 'Kamera topilmadi. Kodni qo‘lda kiriting.'
                : 'Kamerani ochib bo‘lmadi. Qayta urinib ko‘ring yoki kodni qo‘lda kiriting.';
    } finally {
        if (controller === current) starting.value = false;
    }
}
const visibility = () => { if (document.hidden) { stop(); emit('close'); } };
onMounted(() => {
    dialog.value?.showModal();
    document.addEventListener('visibilitychange', visibility);
    void start();
});
onBeforeUnmount(() => {
    stop();
    dialog.value?.close();
    document.removeEventListener('visibilitychange', visibility);
});
</script>
<template>
    <dialog ref="dialog" class="barcode-dialog" aria-labelledby="barcode-capture-title" @cancel.prevent="emit('close')">
        <div class="barcode-dialog-heading"><h2 id="barcode-capture-title">Shtrix-kodni skanerlang</h2><button type="button" class="icon-button" aria-label="Kamerani yopish" @click="emit('close')"><Icon name="plus" class="close-icon" /></button></div>
        <p>Kod o‘qilganda avtomatik inputga yoziladi.</p>
        <div class="camera-box camera-is-active">
            <video ref="video" muted playsinline autoplay aria-label="Shtrix-kod kamerasi" @click="scanner?.focus()"></video>
            <div class="scan-target" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
            <div v-if="starting" class="scanner-progress" role="status"><span class="scanner-spinner" aria-hidden="true"></span>Kamera ochilmoqda…</div>
            <span v-else class="camera-label">Kodni ramkaga tuting</span>
        </div>
        <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
        <button v-if="error" type="button" class="secondary" :disabled="starting" @click="start">Qayta urinish</button>
        <button type="button" class="text-button" @click="emit('close')">Yopish va qo‘lda kiritish</button>
    </dialog>
</template>
