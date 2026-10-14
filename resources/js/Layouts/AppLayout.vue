<script setup>
import { useLayout } from '@/layout/composables/layout';
import { useNotifications } from '@/composables/useNotifications';
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';
import AppFooter from '@/layout/AppFooter.vue';
import AppSidebar from '@/layout/AppSidebar.vue';
import AppTopbar from '@/layout/AppTopbar.vue';

const { layoutConfig, layoutState, hideMobileMenu } = useLayout();

// Inisialisasi notifikasi realtime SEKALI per sesi SPA. Kepemilikan EventSource
// ada di scope modul composable (guard initialized); JANGAN teardown di
// onUnmounted layout — teardown hanya via beforeunload/pagehide (desain §9.3).
const { init } = useNotifications();
onMounted(() => init(usePage()));

const containerClass = computed(() => ({
    'layout-overlay':         layoutConfig.menuMode === 'overlay',
    'layout-static':          layoutConfig.menuMode === 'static',
    'layout-overlay-active':  layoutState.overlayMenuActive,
    'layout-mobile-active':   layoutState.mobileMenuActive,
    'layout-static-inactive': layoutState.staticMenuInactive,
}));
</script>

<template>
    <div class="layout-wrapper" :class="containerClass">
        <AppTopbar />
        <AppSidebar />

        <div class="layout-main-container">
            <div class="layout-main">
                <!-- Inertia renders child page here -->
                <slot />
            </div>
            <AppFooter />
        </div>

        <!-- Mobile overlay backdrop -->
        <div
            class="layout-mask animate-fadein"
            v-if="layoutState.mobileMenuActive"
            @click="hideMobileMenu"
        />

        <!-- Global PrimeVue services -->
        <Toast />
        <ConfirmDialog />
    </div>
</template>
