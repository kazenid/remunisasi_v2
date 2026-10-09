<script setup>
import { useLayout } from '@/layout/composables/layout';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppMenu from './AppMenu.vue';

const { layoutState, isDesktop, hasOpenOverlay } = useLayout();
const page = usePage();
const sidebarRef = ref(null);
let outsideClickListener = null;

const user = computed(() => page.props.auth?.user ?? null);

// Foto SIKEP bisa 404; jika gagal muat, kembali ke ikon pi pi-user.
const sidebarImgError = ref(false);
const showSidebarPhoto = computed(() => !!user.value?.foto_url && !sidebarImgError.value);

function logout() {
    router.post(route('logout'));
}

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

        <!-- Kartu info pengguna + logout di bagian bawah sidebar -->
        <div v-if="user" class="sidebar-user-card">
            <div class="flex items-center gap-3 mb-3">
                <span class="inline-flex items-center justify-center bg-primary text-primary-contrast rounded-full shrink-0 overflow-hidden" style="width:2.25rem;height:2.25rem">
                    <img
                        v-if="showSidebarPhoto"
                        :src="user.foto_url"
                        alt="Foto pegawai"
                        class="w-full h-full object-cover rounded-full"
                        @error="sidebarImgError = true"
                    />
                    <i v-else class="pi pi-user text-sm"></i>
                </span>
                <div class="min-w-0">
                    <div class="font-medium text-surface-900 dark:text-surface-0 text-sm truncate">{{ user.nama }}</div>
                    <div class="text-xs text-muted-color truncate">{{ user.username }}</div>
                </div>
            </div>
            <Button icon="pi pi-sign-out" label="Keluar" severity="secondary" outlined size="small" class="w-full" @click="logout" />
        </div>
    </div>
</template>
