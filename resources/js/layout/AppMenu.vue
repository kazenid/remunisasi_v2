<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppMenuItem from './AppMenuItem.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);

const model = computed(() => {
    const u = user.value;
    const isAdmin = !!u?.is_admin;
    // Menu Katimker/Kabid bersifat personal (tugas saya / penilaian saya),
    // jadi hanya tampil bila user benar-benar memegang peran tsb — bukan karena admin.
    // Kebutuhan lintas-pengguna untuk admin ada di grup Administrasi.
    const showKatimker = !!u?.is_katimker;
    const showKabid = !!u?.is_kabid;

    const menu = [
        {
            label: 'Beranda',
            items: [
                { label: 'Dashboard', icon: 'pi pi-fw pi-home', route: 'dashboard' },
            ],
        },
    ];

    if (showKatimker) {
        menu.push({
            label: 'Katimker',
            items: [
                { label: 'Tugas Saya', icon: 'pi pi-fw pi-list-check', route: 'katimker.tugas.index' },
                { label: 'Rekap Saya', icon: 'pi pi-fw pi-chart-bar', route: 'katimker.rekap.index' },
            ],
        });
    }

    if (showKabid) {
        menu.push({
            label: 'Kabid',
            items: [
                { label: 'Penilaian', icon: 'pi pi-fw pi-check-square', route: 'kabid.penilaian.index' },
                { label: 'Beri Penugasan', icon: 'pi pi-fw pi-plus-circle', route: 'kabid.penugasan.create' },
                { label: 'Rekap Binaan', icon: 'pi pi-fw pi-chart-bar', route: 'kabid.rekap.index' },
            ],
        });
    }

    if (isAdmin) {
        menu.push({
            label: 'Administrasi',
            items: [
                { label: 'Manajemen Pengguna', icon: 'pi pi-fw pi-users', route: 'admin.pengguna.index' },
                { label: 'Pemetaan Approver', icon: 'pi pi-fw pi-sitemap', route: 'admin.pemetaan.index' },
                { label: 'Periode', icon: 'pi pi-fw pi-calendar', route: 'admin.periode.index' },
                { label: 'Rekap Kinerja', icon: 'pi pi-fw pi-chart-bar', route: 'admin.rekap.index' },
                { label: 'Konfigurasi Insentif', icon: 'pi pi-fw pi-sliders-h', route: 'admin.insentif.index' },
                { label: 'Hitung Insentif', icon: 'pi pi-fw pi-calculator', route: 'admin.insentif.hitung.index' },
                { label: 'Log Audit', icon: 'pi pi-fw pi-history', route: 'admin.audit.index' },
                { label: 'Status Integrasi', icon: 'pi pi-fw pi-server', route: 'admin.integrasi.index' },
            ],
        });
    }

    return menu;
});
</script>

<template>
    <ul class="layout-menu">
        <template v-for="(item, i) in model" :key="i">
            <app-menu-item v-if="!item.separator" :item="item" :index="i" />
            <li v-if="item.separator" class="menu-separator" role="separator"></li>
        </template>
    </ul>
</template>
