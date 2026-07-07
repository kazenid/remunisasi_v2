<script setup>
import { useLayout } from '@/layout/composables/layout';
import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import AppMenu from './AppMenu.vue';

const { layoutState, isDesktop, hasOpenOverlay } = useLayout();
const page = usePage();
const sidebarRef = ref(null);
let outsideClickListener = null;

watch(
    () => page.url,
    () => {
        if (isDesktop()) layoutState.activePath = null;
        layoutState.overlayMenuActive = false;
        layoutState.mobileMenuActive = false;
        layoutState.menuHoverActive = false;
    },
    { immediate: true },
);

watch(hasOpenOverlay, (newVal) => {
    if (isDesktop()) {
        if (newVal) bindOutsideClickListener();
        else unbindOutsideClickListener();
    }
});

const bindOutsideClickListener = () => {
    if (!outsideClickListener) {
        outsideClickListener = (event) => {
            if (isOutsideClicked(event)) {
                layoutState.overlayMenuActive = false;
            }
        };
        document.addEventListener('click', outsideClickListener);
    }
};

const unbindOutsideClickListener = () => {
    if (outsideClickListener) {
        document.removeEventListener('click', outsideClickListener);
        outsideClickListener = null;
    }
};

const isOutsideClicked = (event) => {
    const topbarButtonEl = document.querySelector('.layout-menu-button');
    return !(
        sidebarRef.value?.isSameNode(event.target) ||
        sidebarRef.value?.contains(event.target) ||
        topbarButtonEl?.isSameNode(event.target) ||
        topbarButtonEl?.contains(event.target)
    );
};

onBeforeUnmount(() => {
    unbindOutsideClickListener();
});
</script>

<template>
    <div ref="sidebarRef" class="layout-sidebar" role="navigation" aria-label="Main navigation">
        <AppMenu />
    </div>
</template>
