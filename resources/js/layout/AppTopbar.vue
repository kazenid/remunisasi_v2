<script setup>
import { useLayout } from '@/layout/composables/layout';
import AppConfigurator from './AppConfigurator.vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const { toggleMenu, toggleDarkMode, isDarkTheme } = useLayout();
const page = usePage();
const user = computed(() => page.props.auth?.user || {});

const logout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <div class="layout-topbar">
        <div class="layout-topbar-logo-container">
            <button class="layout-menu-button layout-topbar-action" @click="toggleMenu" aria-label="Toggle sidebar">
                <i class="pi pi-bars"></i>
            </button>
            <Link href="/" class="layout-topbar-logo flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-primary flex items-center justify-center text-primary-contrast font-bold shadow-sm">
                    <i class="pi pi-chart-line text-lg"></i>
                </div>
                <div class="flex flex-col">
                    <span class="text-base font-bold tracking-tight text-surface-900 dark:text-surface-0 leading-tight">REMUNISASI</span>
                    <span class="text-[10px] tracking-wider uppercase font-semibold text-muted-color">RSUD BANGIL</span>
                </div>
            </Link>
        </div>

        <div class="layout-topbar-actions">
            <div class="layout-config-menu flex items-center gap-1">
                <button type="button" class="layout-topbar-action" @click="toggleDarkMode" :aria-label="isDarkTheme ? 'Light mode' : 'Dark mode'" title="Ganti Tema">
                    <i :class="['pi', { 'pi-moon': isDarkTheme, 'pi-sun': !isDarkTheme }]"></i>
                </button>
                <div class="relative">
                    <button
                        v-styleclass="{ selector: '@next', enterFromClass: 'hidden', enterActiveClass: 'animate-slidedown', leaveToClass: 'hidden', hideOnOutsideClick: true }"
                        type="button"
                        class="layout-topbar-action layout-topbar-action-highlight"
                        aria-label="Theme configurator"
                        title="Warna Aplikasi"
                    >
                        <i class="pi pi-palette"></i>
                    </button>
                    <AppConfigurator />
                </div>
            </div>

            <!-- User profile & Logout -->
            <div class="flex items-center gap-3 pl-2 border-l border-surface-200 dark:border-surface-700">
                <div class="hidden sm:flex flex-col text-right leading-tight">
                    <span class="text-sm font-semibold text-surface-900 dark:text-surface-0">{{ user.nama_lengkap || user.name || 'Pegawai' }}</span>
                    <span class="text-xs text-muted-color capitalize">{{ user.nama_unit || 'RSUD Bangil' }} ({{ user.role || 'Staf' }})</span>
                </div>
                <button
                    type="button"
                    @click="logout"
                    class="layout-topbar-action text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors"
                    title="Keluar / Logout"
                >
                    <i class="pi pi-power-off"></i>
                </button>
            </div>
        </div>
    </div>
</template>

