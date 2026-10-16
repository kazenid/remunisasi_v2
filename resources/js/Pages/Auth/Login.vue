<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FloatingConfigurator from '@/Components/FloatingConfigurator.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { asset } from '@/composables/useAsset.js';

defineOptions({ layout: GuestLayout });

const props = defineProps({
    turnstileSiteKey: { type: String, default: null },
});

const form = useForm({
    login: '',
    password: '',
    remember: false,
    turnstile_token: '',
});

const tahun = computed(() => new Date().getFullYear());

// ─── Cloudflare Turnstile (aktif hanya bila site key dikonfigurasi) ───
const turnstileEl = ref(null);
let turnstileWidgetId = null;

function renderTurnstile() {
    if (!props.turnstileSiteKey || !turnstileEl.value || !window.turnstile) return;
    turnstileWidgetId = window.turnstile.render(turnstileEl.value, {
        sitekey: props.turnstileSiteKey,
        callback: (token) => { form.turnstile_token = token; },
        'expired-callback': () => { form.turnstile_token = ''; },
        'error-callback': () => { form.turnstile_token = ''; },
    });
}

onMounted(() => {
    if (!props.turnstileSiteKey) return;
    if (window.turnstile) {
        renderTurnstile();
        return;
    }
    const script = document.createElement('script');
    script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
    script.async = true;
    script.onload = renderTurnstile;
    document.head.appendChild(script);
});

onBeforeUnmount(() => {
    if (turnstileWidgetId !== null && window.turnstile) window.turnstile.remove(turnstileWidgetId);
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
            // Token Turnstile sekali pakai: minta token baru setelah setiap percobaan.
            form.turnstile_token = '';
            if (turnstileWidgetId !== null && window.turnstile) window.turnstile.reset(turnstileWidgetId);
        },
    });
};
</script>

<template>
    <Head title="Masuk" />
    <FloatingConfigurator />

    <div class="w-full min-h-screen flex items-stretch">
        <div class="grid grid-cols-12 w-full">
            <!-- Panel branding (kiri) -->
            <div
                class="hidden lg:flex lg:col-span-6 xl:col-span-7 flex-col justify-between p-10 xl:p-16 text-white relative overflow-hidden"
                style="background: linear-gradient(135deg, var(--p-primary-600) 0%, var(--p-primary-500) 45%, var(--p-primary-400) 100%)"
            >
                <div class="flex items-center gap-3">
                    <div class="inline-flex items-center justify-center bg-white/15 rounded-2xl p-3 backdrop-blur-sm">
                        <img :src="asset('images/logo_rsud_bangil.png')" alt="Logo RSUD Bangil" class="h-10 w-auto" />
                    </div>
                    <div class="flex flex-col leading-tight">
                        <span class="text-xl font-semibold tracking-wide">TUKIMAN</span>
                        <span class="text-white/80 text-sm">Tugas dan Kinerja Manajemen</span>
                    </div>
                </div>

                <div class="max-w-xl">
                    <h1 class="text-4xl xl:text-5xl font-bold leading-tight mb-4">
                        Sistem Remunerasi Pegawai RSUD Bangil
                    </h1>
                    <p class="text-white/80 text-lg mb-8">
                        Kelola kinerja, target, dan insentif pegawai dalam satu platform terintegrasi dengan data kepegawaian SIKEP.
                    </p>

                    <ul class="flex flex-col gap-4">
                        <li class="flex items-start gap-3">
                            <i class="pi pi-check-circle text-xl mt-0.5"></i>
                            <span class="text-white/90">Masuk memakai akun SIKEP yang sudah Anda miliki.</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="pi pi-chart-line text-xl mt-0.5"></i>
                            <span class="text-white/90">Pantau capaian target dan perhitungan insentif secara transparan.</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="pi pi-shield text-xl mt-0.5"></i>
                            <span class="text-white/90">Terintegrasi aman dengan basis data kepegawaian rumah sakit.</span>
                        </li>
                    </ul>
                </div>

                <div class="text-white/70 text-sm">
                    &copy; {{ tahun }} RSUD Bangil &middot; Pemerintah Kabupaten Pasuruan
                </div>
            </div>

            <!-- Panel form (kanan) -->
            <div class="col-span-12 lg:col-span-6 xl:col-span-5 flex items-center justify-center p-6 sm:p-10 bg-surface-50 dark:bg-surface-950">
                <div class="w-full max-w-md">
                    <!-- Header ringkas untuk mobile (panel kiri tersembunyi) -->
                    <div class="flex flex-col items-center text-center mb-8 lg:items-start lg:text-left">
                        <div class="inline-flex items-center justify-center mb-5 p-4 bg-surface-0 dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 shadow-sm">
                            <img :src="asset('images/logo_rsud_bangil.png')" alt="Logo RSUD Bangil" class="h-14 w-auto" />
                        </div>
                        <h2 class="text-surface-900 dark:text-surface-0 text-3xl font-semibold mb-2">Selamat datang</h2>
                        <p class="text-muted-color">Masuk dengan akun SIKEP Anda untuk melanjutkan.</p>
                    </div>

                    <div class="card mb-0">
                        <Message
                            v-if="form.errors.login"
                            severity="error"
                            :closable="false"
                            class="mb-6"
                        >
                            {{ form.errors.login }}
                        </Message>

                        <form @submit.prevent="submit">
                            <div class="flex flex-col gap-2 mb-5">
                                <label for="login" class="font-medium text-surface-900 dark:text-surface-0">
                                    Username / No. Absen / No. Pegawai
                                </label>
                                <IconField>
                                    <InputIcon class="pi pi-user" />
                                    <InputText
                                        id="login"
                                        v-model="form.login"
                                        type="text"
                                        placeholder="Masukkan username atau nomor Anda"
                                        class="w-full"
                                        :class="{ 'p-invalid': form.errors.login }"
                                        autocomplete="username"
                                        autofocus
                                    />
                                </IconField>
                                <small v-if="form.errors.login" class="text-red-500">{{ form.errors.login }}</small>
                            </div>

                            <div class="flex flex-col gap-2 mb-5">
                                <label for="password" class="font-medium text-surface-900 dark:text-surface-0">Password</label>
                                <Password
                                    id="password"
                                    v-model="form.password"
                                    placeholder="Masukkan password"
                                    :toggleMask="true"
                                    inputClass="w-full"
                                    class="w-full"
                                    fluid
                                    :feedback="false"
                                    :class="{ 'p-invalid': form.errors.password }"
                                    autocomplete="current-password"
                                />
                                <small v-if="form.errors.password" class="text-red-500">{{ form.errors.password }}</small>
                            </div>

                            <div class="flex items-center mb-8">
                                <Checkbox v-model="form.remember" inputId="remember" binary class="mr-2" />
                                <label for="remember" class="text-surface-700 dark:text-surface-200 cursor-pointer">Ingat saya</label>
                            </div>

                            <div v-if="turnstileSiteKey" ref="turnstileEl" class="flex justify-center mb-6"></div>

                            <Button
                                type="submit"
                                label="Masuk"
                                icon="pi pi-sign-in"
                                class="w-full"
                                :loading="form.processing"
                                :disabled="!!turnstileSiteKey && !form.turnstile_token"
                            />
                        </form>
                    </div>

                    <p class="text-center text-muted-color text-sm mt-6 lg:hidden">
                        &copy; {{ tahun }} RSUD Bangil &middot; Sistem Remunerasi Pegawai
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
