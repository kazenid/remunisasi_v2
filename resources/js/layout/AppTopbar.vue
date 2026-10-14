<script setup>
import { useLayout } from '@/layout/composables/layout';
import { useNotifications } from '@/composables/useNotifications';
import AppConfigurator from './AppConfigurator.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const { toggleMenu, toggleDarkMode, isDarkTheme } = useLayout();

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);

// ─── Notifikasi (lonceng + riwayat) ───────────────────────────────────────
const { items, hasUnread, markRead, markAllRead, relativeTime } = useNotifications();
const op = ref(null);

function toggleNotif(event) {
    op.value?.toggle(event);
}

function onNotifClick(n) {
    op.value?.hide();
    markRead(n);
}

// Foto SIKEP bisa 404; jika gagal muat, kembali ke ikon pi pi-user.
const triggerImgError = ref(false);
const panelImgError = ref(false);
const showTriggerPhoto = computed(() => !!user.value?.foto_url && !triggerImgError.value);
const showPanelPhoto = computed(() => !!user.value?.foto_url && !panelImgError.value);

const peran = computed(() => {
    const u = user.value;
    if (!u) return [];
    const r = [];
    if (u.is_admin) r.push('Admin');
    if (u.is_katimker) r.push('Katimker');
    if (u.is_kabid) r.push('Kabid');
    return r;
});

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <div class="layout-topbar">
        <div class="layout-topbar-logo-container">
            <button class="layout-menu-button layout-topbar-action" @click="toggleMenu" aria-label="Toggle sidebar">
                <i class="pi pi-bars"></i>
            </button>
            <Link href="/" class="layout-topbar-logo">
                <img src="/images/logo_rsud_bangil.png" alt="Logo RSUD Bangil" />
                <span>TUKIMAN</span>
            </Link>
        </div>

        <div class="layout-topbar-actions">
            <div class="layout-config-menu flex items-center gap-1">
                <!-- Lonceng notifikasi: titik merah tanpa angka + riwayat yang bisa diklik -->
                <div class="relative">
                    <OverlayBadge v-if="hasUnread" severity="danger">
                        <button type="button" class="layout-topbar-action" @click="toggleNotif" aria-label="Notifikasi">
                            <i class="pi pi-bell"></i>
                        </button>
                    </OverlayBadge>
                    <button v-else type="button" class="layout-topbar-action" @click="toggleNotif" aria-label="Notifikasi">
                        <i class="pi pi-bell"></i>
                    </button>

                    <Popover ref="op">
                        <div class="w-80">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-medium text-surface-900 dark:text-surface-0">Notifikasi</span>
                                <Button link size="small" label="Tandai semua terbaca" @click="markAllRead" />
                            </div>
                            <ul class="max-h-80 overflow-auto">
                                <li v-for="n in items" :key="n.id">
                                    <button
                                        type="button"
                                        class="w-full text-left p-2 rounded-border hover:bg-surface-100 dark:hover:bg-surface-800"
                                        :class="{ 'font-semibold': !n.read_at }"
                                        @click="onNotifClick(n)"
                                    >
                                        <div class="text-sm text-surface-900 dark:text-surface-0">{{ n.message }}</div>
                                        <div class="text-xs text-muted-color">{{ relativeTime(n.created_at) }}</div>
                                    </button>
                                </li>
                                <li v-if="!items.length" class="p-3 text-sm text-muted-color">Belum ada notifikasi.</li>
                            </ul>
                        </div>
                    </Popover>
                </div>

                <button type="button" class="layout-topbar-action" @click="toggleDarkMode" :aria-label="isDarkTheme ? 'Switch to light mode' : 'Switch to dark mode'">
                    <i :class="['pi', { 'pi-moon': isDarkTheme, 'pi-sun': !isDarkTheme }]"></i>
                </button>
                <div class="relative">
                    <button
                        v-styleclass="{ selector: '@next', enterFromClass: 'hidden', enterActiveClass: 'animate-slidedown', leaveToClass: 'hidden', hideOnOutsideClick: true }"
                        type="button"
                        class="layout-topbar-action layout-topbar-action-highlight"
                        aria-label="Theme configurator"
                    >
                        <i class="pi pi-palette"></i>
                    </button>
                    <AppConfigurator />
                </div>

                <!-- Tombol pengguna: minimalis & sejajar dgn tema, klik untuk panel info + logout -->
                <div class="relative">
                    <button
                        v-styleclass="{ selector: '@next', enterFromClass: 'hidden', enterActiveClass: 'animate-slidedown', leaveToClass: 'hidden', hideOnOutsideClick: true }"
                        type="button"
                        class="layout-topbar-action "
                        aria-label="Menu pengguna"
                    >
                        <img
                            v-if="showTriggerPhoto"
                            :src="user.foto_url"
                            alt="Foto pegawai"
                            class="rounded-full object-cover"
                            style="width:1.5rem;height:1.5rem"
                            @error="triggerImgError = true"
                        />
                        <i v-else class="pi pi-user"></i>
                    </button>

                    <div class="user-panel hidden animate-slidedown">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="inline-flex items-center justify-center bg-primary text-primary-contrast rounded-full shrink-0 overflow-hidden" style="width:2.5rem;height:2.5rem">
                                <img
                                    v-if="showPanelPhoto"
                                    :src="user.foto_url"
                                    alt="Foto pegawai"
                                    class="w-full h-full object-cover rounded-full"
                                    @error="panelImgError = true"
                                />
                                <i v-else class="pi pi-user"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="font-medium text-surface-900 dark:text-surface-0 truncate">{{ user?.nama ?? '-' }}</div>
                                <div class="text-sm text-muted-color truncate">{{ user?.username }}</div>
                            </div>
                        </div>
                        <div v-if="peran.length" class="flex flex-wrap gap-1 mb-3">
                            <Tag v-for="p in peran" :key="p" :value="p" severity="secondary" />
                        </div>
                        <Button icon="pi pi-sign-out" label="Keluar" severity="secondary" outlined class="w-full" @click="logout" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
