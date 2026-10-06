<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FloatingConfigurator from '@/Components/FloatingConfigurator.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineOptions({ layout: GuestLayout });

const form = useForm({
    login: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Masuk - Remunisasi Kinerja" />
    <FloatingConfigurator />

    <div class="flex flex-col items-center justify-center min-h-screen py-12 px-4">
        <div style="border-radius:32px;padding:0.25rem;background:linear-gradient(180deg,var(--p-primary-color) 10%,rgba(33,150,243,0) 30%)" class="w-full max-w-md">
            <div class="w-full bg-surface-0 dark:bg-surface-900 py-10 px-8 sm:px-12 shadow-xl" style="border-radius:30px">
                <div class="text-center mb-8">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-primary/10 text-primary mb-4">
                        <i class="pi pi-chart-line text-3xl"></i>
                    </div>
                    <div class="text-surface-900 dark:text-surface-0 text-2xl font-bold mb-1">E-Remunerasi</div>
                    <div class="text-surface-600 dark:text-surface-400 text-sm font-semibold">RSUD BANGIL KABUPATEN PASURUAN</div>
                    <span class="text-xs text-muted-color mt-2 block">Masuk menggunakan akun SIKEP Kepegawaian</span>
                </div>

                <form @submit.prevent="submit">
                    <div class="mb-5">
                        <label for="login" class="block text-surface-900 dark:text-surface-0 text-sm font-semibold mb-2">Username / No. Absen</label>
                        <InputText
                            id="login"
                            v-model="form.login"
                            type="text"
                            placeholder="Contoh: 1010012 atau username"
                            class="w-full"
                            :class="{ 'p-invalid': form.errors.login }"
                            autocomplete="username"
                            autofocus
                        />
                        <small v-if="form.errors.login" class="text-red-500 mt-1 block">{{ form.errors.login }}</small>
                    </div>

                    <div class="mb-5">
                        <label for="password" class="block text-surface-900 dark:text-surface-0 font-semibold text-sm mb-2">Password SIKEP</label>
                        <Password
                            id="password"
                            v-model="form.password"
                            placeholder="Masukkan password Anda"
                            :toggleMask="true"
                            fluid
                            :feedback="false"
                            :class="{ 'p-invalid': form.errors.password }"
                            autocomplete="current-password"
                        />
                        <small v-if="form.errors.password" class="text-red-500 mt-1 block">{{ form.errors.password }}</small>
                    </div>

                    <div class="flex items-center justify-between mt-2 mb-6">
                        <div class="flex items-center">
                            <Checkbox v-model="form.remember" id="remember" binary class="mr-2" />
                            <label for="remember" class="text-sm cursor-pointer select-none">Ingat saya</label>
                        </div>
                    </div>

                    <Button
                        type="submit"
                        label="Masuk ke Sistem"
                        icon="pi pi-sign-in"
                        class="w-full font-semibold"
                        :loading="form.processing"
                    />
                </form>

                <div class="text-center mt-8 pt-4 border-t border-surface-200 dark:border-surface-700 text-xs text-muted-color">
                    Terintegrasi langsung dengan database SIKEP Kepegawaian & Presensi
                </div>
            </div>
        </div>
    </div>
</template>
