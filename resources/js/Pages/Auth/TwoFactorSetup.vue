<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FloatingConfigurator from '@/Components/FloatingConfigurator.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { computed } from 'vue';
import { asset } from '@/composables/useAsset.js';

defineOptions({ layout: GuestLayout });

const props = defineProps({
    qrSvg: { type: String, default: null },
    secret: { type: String, default: null },
    recoveryCodes: { type: Array, default: () => [] },
    justConfirmed: { type: Boolean, default: false },
});

const toast = useToast();

// Fase saling-eksklusif.
const faseRecovery = computed(() => props.justConfirmed === true);
const faseEnrollment = computed(() => !props.justConfirmed && !!props.qrSvg);

const tahun = computed(() => new Date().getFullYear());

const form = useForm({
    code: '',
});

function aktifkan() {
    form.post(route('two-factor.setup.confirm'), {
        onError: () => form.reset('code'),
    });
}

function salinKunci() {
    if (!props.secret) {
        return;
    }
    navigator.clipboard?.writeText(props.secret).then(() => {
        toast.add({ severity: 'success', summary: 'Disalin', detail: 'Kunci rahasia disalin.', life: 3000 });
    });
}

function salinSemuaKode() {
    const teks = props.recoveryCodes.join('\n');
    navigator.clipboard?.writeText(teks).then(() => {
        toast.add({ severity: 'success', summary: 'Disalin', detail: 'Semua kode pemulihan disalin.', life: 3000 });
    });
}

function unduhKode() {
    const teks = props.recoveryCodes.join('\n');
    const blob = new Blob([teks], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'kode-pemulihan-remunisasi.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function selesai() {
    router.visit(route('dashboard'));
}

function keluar() {
    router.post(route('logout'));
}
</script>

<template>
    <Head title="Aktifkan Verifikasi Dua Langkah" />
    <FloatingConfigurator />

    <div class="w-full min-h-screen flex items-center justify-center p-6 sm:p-10 bg-surface-50 dark:bg-surface-950">
        <div class="w-full max-w-lg">
            <div class="flex items-center justify-end mb-4">
                <Button
                    label="Keluar"
                    icon="pi pi-sign-out"
                    severity="secondary"
                    text
                    @click="keluar"
                />
            </div>

            <div class="flex flex-col items-center text-center mb-8">
                <div class="inline-flex items-center justify-center mb-5 p-4 bg-surface-0 dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 shadow-sm">
                    <img :src="asset('images/logo_rsud_bangil.png')" alt="Logo RSUD Bangil" class="h-14 w-auto" />
                </div>
                <h2 class="text-surface-900 dark:text-surface-0 text-3xl font-semibold mb-2">
                    {{ faseRecovery ? 'Simpan Kode Pemulihan' : 'Aktifkan Verifikasi Dua Langkah' }}
                </h2>
                <p class="text-muted-color">
                    {{ faseRecovery
                        ? 'Simpan kode berikut di tempat aman. Kode ini hanya ditampilkan sekali.'
                        : 'Akun admin wajib mengaktifkan verifikasi dua langkah sebelum melanjutkan.' }}
                </p>
            </div>

            <!-- Fase enrollment: QR + kunci manual + konfirmasi -->
            <div v-if="faseEnrollment" class="card mb-0">
                <ol class="text-muted-color text-sm mb-6 list-decimal pl-5 flex flex-col gap-1">
                    <li>Buka aplikasi authenticator (Google Authenticator, Authy, dll.).</li>
                    <li>Pindai kode QR di bawah, atau masukkan kunci rahasia secara manual.</li>
                    <li>Masukkan kode 6 digit yang muncul untuk mengaktifkan.</li>
                </ol>

                <div class="flex justify-center mb-6">
                    <div class="bg-surface-0 dark:bg-surface-0 p-4 rounded-border inline-block" v-html="qrSvg" />
                </div>

                <div class="flex flex-col gap-2 mb-6">
                    <label class="font-medium text-surface-900 dark:text-surface-0">Kunci Rahasia (manual)</label>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 px-3 py-2 rounded-border bg-surface-100 dark:bg-surface-800 text-surface-900 dark:text-surface-0 font-mono text-sm break-all">
                            {{ secret }}
                        </code>
                        <Button
                            icon="pi pi-copy"
                            severity="secondary"
                            outlined
                            aria-label="Salin kunci"
                            @click="salinKunci"
                        />
                    </div>
                </div>

                <form @submit.prevent="aktifkan">
                    <Message
                        v-if="form.errors.code"
                        severity="error"
                        :closable="false"
                        class="mb-6"
                    >
                        {{ form.errors.code }}
                    </Message>

                    <div class="flex flex-col items-center gap-2 mb-6">
                        <label class="font-medium text-surface-900 dark:text-surface-0 self-start">
                            Kode Authenticator
                        </label>
                        <InputOtp
                            v-model="form.code"
                            :length="6"
                            integerOnly
                            :inputmode="'numeric'"
                            :class="{ 'p-invalid': form.errors.code }"
                        />
                    </div>

                    <Button
                        type="submit"
                        label="Aktifkan 2FA"
                        icon="pi pi-shield"
                        class="w-full"
                        :loading="form.processing"
                    />
                </form>
            </div>

            <!-- Fase recovery codes (sekali) -->
            <div v-else-if="faseRecovery" class="card mb-0">
                <Message severity="warn" :closable="false" class="mb-6">
                    Simpan kode ini sekarang. Kode tidak akan ditampilkan lagi.
                </Message>

                <div class="grid grid-cols-2 gap-3 mb-6">
                    <code
                        v-for="kode in recoveryCodes"
                        :key="kode"
                        class="px-3 py-2 rounded-border bg-surface-100 dark:bg-surface-800 text-surface-900 dark:text-surface-0 font-mono text-center tracking-wider"
                    >
                        {{ kode }}
                    </code>
                </div>

                <div class="flex flex-col sm:flex-row gap-3 mb-6">
                    <Button
                        label="Salin semua"
                        icon="pi pi-copy"
                        severity="secondary"
                        outlined
                        class="flex-1"
                        @click="salinSemuaKode"
                    />
                    <Button
                        label="Unduh .txt"
                        icon="pi pi-download"
                        severity="secondary"
                        outlined
                        class="flex-1"
                        @click="unduhKode"
                    />
                </div>

                <Button
                    label="Selesai, lanjut ke dashboard"
                    icon="pi pi-arrow-right"
                    class="w-full"
                    @click="selesai"
                />
            </div>

            <p class="text-center text-muted-color text-sm mt-6">
                &copy; {{ tahun }} RSUD Bangil &middot; Sistem Remunerasi Pegawai
            </p>
        </div>
    </div>
</template>
