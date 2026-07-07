<script setup>
import { useLayout } from '@/layout/composables/layout';
import { computed } from 'vue';
import AppFooter from '@/layout/AppFooter.vue';
import AppSidebar from '@/layout/AppSidebar.vue';
import AppTopbar from '@/layout/AppTopbar.vue';

const { layoutConfig, layoutState, hideMobileMenu } = useLayout();

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
