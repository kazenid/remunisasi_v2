<script setup>
import { computed } from 'vue';
import AppMenuItem from './AppMenuItem.vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const user = computed(() => page.props.auth?.user || {});

const model = computed(() => {
    const isApprover = ['katker', 'kepala_unit', 'kabid', 'wadir', 'direktur', 'admin'].includes(user.value.role) || Boolean(user.value.is_katimker);
    const isAdmin = ['admin', 'direktur'].includes(user.value.role);

    const items = [
        {
            label: 'Menu Remunerasi',
            items: [
                {
                    label: 'Dashboard Kinerja',
                    icon: 'pi pi-fw pi-chart-pie',
                    route: 'dashboard',
                },
                {
                    label: 'Log Tugas Harian',
                    icon: 'pi pi-fw pi-book',
                    route: 'tugas.index',
                },
            ],
        },
    ];

    if (isApprover) {
        items[0].items.push({
            label: 'Verifikasi Kinerja',
            icon: 'pi pi-fw pi-check-square',
            route: 'approval.index',
        });
    }

    items.push({
        label: 'Laporan & Pengaturan',
        items: [
            {
                label: 'Rekapitulasi Insentif',
                icon: 'pi pi-fw pi-calculator',
                route: 'rekap.index',
            },
            ...(isAdmin ? [
                {
                    label: 'Periode Kinerja',
                    icon: 'pi pi-fw pi-calendar-times',
                    route: 'periode.index',
                },
                {
                    label: 'Manajemen Katimker',
                    icon: 'pi pi-fw pi-users',
                    route: 'admin.katimker.index',
                },
            ] : []),
        ],
    });

    return items;
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

