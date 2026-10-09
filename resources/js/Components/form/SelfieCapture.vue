<script setup>
import { ref, onBeforeUnmount } from 'vue';

// Menangkap foto selfie via kamera (getUserMedia), dengan fallback unggah berkas.
// Mengemit event 'captured' berisi objek File, atau 'tooLarge' bila file melebihi batas.
const props = defineProps({
    // Batas ukuran foto selfie dalam MB (sinkron dengan rule backend max:5120 = 5 MB).
    maxSizeMb: { type: Number, default: 5 },
});
const emit = defineEmits(['captured', 'tooLarge']);

// Penjaga ukuran sisi klien: tolak file oversize sebelum di-emit (jangan ikut dikirim).
function dalamBatas(file) {
    const batas = props.maxSizeMb * 1024 * 1024;
    if (file.size > batas) {
        emit('tooLarge', { name: file.name, maxMb: props.maxSizeMb });
        return false;
    }
    return true;
}

const videoRef = ref(null);
const streaming = ref(false);
const error = ref(null);
let stream = null;

async function mulaiKamera() {
    error.value = null;
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
        streaming.value = true;
        // tunggu DOM video ter-render
        requestAnimationFrame(() => {
            if (videoRef.value) {
                videoRef.value.srcObject = stream;
                videoRef.value.play();
            }
        });
    } catch (e) {
        error.value = 'Kamera tidak tersedia atau izin ditolak. Gunakan unggah berkas.';
        streaming.value = false;
    }
}

function stopKamera() {
    if (stream) {
        stream.getTracks().forEach((t) => t.stop());
        stream = null;
    }
    streaming.value = false;
}

function ambil() {
    const video = videoRef.value;
    if (!video) return;

    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);

    canvas.toBlob((blob) => {
        const file = new File([blob], `selfie-${Date.now()}.jpg`, { type: 'image/jpeg' });
        if (dalamBatas(file)) emit('captured', file);
        stopKamera();
    }, 'image/jpeg', 0.9);
}

const fileInputRef = ref(null);

function pilihBerkas() {
    fileInputRef.value?.click();
}

function onFile(event) {
    const files = Array.from(event.target.files ?? []);
    files.forEach((f) => {
        if (dalamBatas(f)) emit('captured', f);
    });
    event.target.value = '';
}

onBeforeUnmount(stopKamera);
</script>

<template>
    <div class="flex flex-col gap-3">
        <div v-if="streaming" class="flex flex-col gap-2">
            <video ref="videoRef" class="w-full max-w-sm rounded-border border border-surface-200 dark:border-surface-700" muted playsinline />
            <div class="flex gap-2">
                <Button label="Ambil Foto" icon="pi pi-camera" size="small" @click="ambil" />
                <Button label="Batal" icon="pi pi-times" severity="secondary" size="small" @click="stopKamera" />
            </div>
        </div>

        <div v-else class="flex flex-wrap gap-2 items-center">
            <Button label="Buka Kamera" icon="pi pi-camera" size="small" @click="mulaiKamera" />
            <span class="text-muted-color text-sm">atau</span>
            <input ref="fileInputRef" type="file" accept="image/jpeg,image/png" class="hidden" multiple @change="onFile" />
            <Button label="Unggah Foto" icon="pi pi-upload" severity="secondary" size="small" @click="pilihBerkas" />
        </div>

        <small v-if="error" class="text-orange-500">{{ error }}</small>
    </div>
</template>
