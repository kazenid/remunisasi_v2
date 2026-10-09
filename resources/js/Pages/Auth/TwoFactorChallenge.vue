<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FloatingConfigurator from '@/Components/FloatingConfigurator.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: GuestLayout });

const form = useForm({
    mode: 'totp',
    code: '',
});

const modeRecovery = computed(() => form.mode === 'recovery');

const tahun = computed(() => new Date().getFullYear());

function gunakanRecovery() {
    form.mode = 'recovery';
    form.code = '';
    form.clearErrors();
}

function gunakanTotp() {
    form.mode = 'totp';
    form.code = '';
    form.clearErrors();
}

function submit() {
    form.post(route('two-factor.challenge.verify'), {
        onError: () => form.reset('code'),
    });
}

function keluar() {
    router.post(route('logout'));
}
</script>

<template>
    <Head title="Verifikasi Dua Langkah" />
    <FloatingConfigurator />

    <div class="w-full min-h-screen flex items-center justify-center p-6 sm:p-10 bg-surface-50 dark:bg-surface-950">
        <div class="w-full max-w-md">
            <div class="flex flex-col items-center text-center mb-8">
                <div class="inline-flex items-center justify-center mb-5 p-4 bg-surface-0 dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 shadow-sm">
                    <img src="/images/logo_rsud_bangil.png" alt="Logo RSUD Bangil" class="h-14 w-auto" />
                </div>
                <h2 class="text-surface-900 dark:text-surface-0 text-3xl font-semibold mb-2">Verifikasi Dua Langkah</h2>
                <p class="text-muted-color">
                    {{ modeRecovery
                        ? 'Masukkan salah satu kode pemulihan yang Anda simpan.'
                        : 'Masukkan kode 6 digit dari aplikasi authenticator Anda.' }}
                </p>
            </div>

            <div class="card mb-0">
                <Message
                    v-if="form.errors.code"
                    severity="error"
                    :closable="false"
                    class="mb-6"
                >
                    {{ form.errors.code }}
                </Message>

                <form @submit.prevent="submit">
                    <!-- Mode TOTP: input 6 digit -->
                    <div v-if="!modeRecovery" class="flex flex-col items-center gap-2 mb-6">
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

                    <!-- Mode recovery: input kode pemulihan -->
                    <div v-else class="flex flex-col gap-2 mb-6">
                        <label for="recovery" class="font-medium text-surface-900 dark:text-surface-0">
                            Kode Pemulihan
                        </label>
                        <InputText
                            id="recovery"
                            v-model="form.code"
                            placeholder="XXXX-XXXX"
                            class="w-full font-mono"
                            :class="{ 'p-invalid': form.errors.code }"
                            autocomplete="one-time-code"
                            autofocus
                        />
                    </div>

                    <Button
                        type="submit"
                        label="Verifikasi"
                        icon="pi pi-check"
                        class="w-full"
                        :loading="form.processing"
                    />
                </form>

                <div class="flex items-center justify-between mt-6">
                    <Button
                        v-if="!modeRecovery"
                        label="Gunakan kode pemulihan"
                        icon="pi pi-key"
                        severity="secondary"
                        text
                        @click="gunakanRecovery"
                    />
                    <Button
                        v-else
                        label="Gunakan aplikasi authenticator"
                        icon="pi pi-mobile"
                        severity="secondary"
                        text
                        @click="gunakanTotp"
                    />
                    <Button
                        label="Keluar"
                        icon="pi pi-sign-out"
                        severity="secondary"
                        text
                        @click="keluar"
                    />
                </div>
            </div>

            <p class="text-center text-muted-color text-sm mt-6">
                &copy; {{ tahun }} RSUD Bangil &middot; Sistem Remunerasi Pegawai
            </p>
        </div>
    </div>
</template>
