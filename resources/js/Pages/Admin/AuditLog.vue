<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const bulanOptions = [{ label: 'Semua bulan', value: null }, ...namaBulan.map((n, i) => ({ label: n, value: i + 1 }))];

const t = new Date().getFullYear();
const tahunOptions = [{ label: 'Semua tahun', value: null }, ...[t - 1, t, t + 1].map((y) => ({ label: String(y), value: y }))];

const fAktor = ref(props.filters.aktor ?? null);
const fTahun = ref(props.filters.tahun ?? null);
const fBulan = ref(props.filters.bulan ?? null);

const aksiMeta = {
    'task.submit': { label: 'Ajukan', severity: 'info' },
    'task.approve': { label: 'Setujui', severity: 'success' },
    'task.reject': { label: 'Tolak', severity: 'danger' },
    'mapping.update': { label: 'Pemetaan', severity: 'secondary' },
};

function terapkan() {
    router.get(route('admin.audit.index'), {
        aktor: fAktor.value || undefined,
        tahun: fTahun.value || undefined,
        bulan: fBulan.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}

function reset() {
    fAktor.value = null; fTahun.value = null; fBulan.value = null;
    terapkan();
}

function pindahHalaman(page) {
    router.get(route('admin.audit.index'), {
        aktor: fAktor.value || undefined, tahun: fTahun.value || undefined, bulan: fBulan.value || undefined, page,
    }, { preserveState: true, preserveScroll: true });
}

function ringkasMeta(meta) {
    if (!meta) return '—';
    return Object.entries(meta)
        .map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`)
        .join('; ');
}
</script>

<template>
    <Head title="Audit" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12">
            <div class="card">
                <div class="flex flex-wrap items-end justify-between gap-4 mb-4">
                    <h4 class="m-0 font-semibold text-xl">Log Audit</h4>
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm text-muted-color">ID Aktor (SIKEP)</label>
                            <InputNumber v-model="fAktor" :useGrouping="false" showButtons :min="0" inputClass="w-32" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm text-muted-color">Bulan</label>
                            <Select v-model="fBulan" :options="bulanOptions" optionLabel="label" optionValue="value" class="w-40" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm text-muted-color">Tahun</label>
                            <Select v-model="fTahun" :options="tahunOptions" optionLabel="label" optionValue="value" class="w-36" />
                        </div>
                        <Button label="Terapkan" icon="pi pi-filter" @click="terapkan" />
                        <Button label="Reset" icon="pi pi-times" severity="secondary" outlined @click="reset" />
                    </div>
                </div>

                <DataTable :value="logs.data" dataKey="id" responsiveLayout="scroll" stripedRows>
                    <template #empty>
                        <div class="text-center text-muted-color py-6">Tidak ada catatan audit untuk filter ini.</div>
                    </template>
                    <Column header="Waktu" style="min-width:12rem">
                        <template #body="{ data }">{{ data.created_at }}</template>
                    </Column>
                    <Column header="Aktor" style="min-width:14rem">
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <span class="font-medium">{{ data.aktor_nama }}</span>
                                <span v-if="data.aktor_sikep_user_id" class="text-xs text-muted-color">#{{ data.aktor_sikep_user_id }}</span>
                            </div>
                        </template>
                    </Column>
                    <Column header="Aksi" style="min-width:9rem">
                        <template #body="{ data }">
                            <Tag :value="aksiMeta[data.aksi]?.label ?? data.aksi" :severity="aksiMeta[data.aksi]?.severity ?? 'secondary'" />
                        </template>
                    </Column>
                    <Column header="Subjek" style="min-width:10rem">
                        <template #body="{ data }">
                            <span v-if="data.subject_type">{{ data.subject_type }} #{{ data.subject_id }}</span>
                            <span v-else class="text-muted-color">—</span>
                        </template>
                    </Column>
                    <Column header="Detail" style="min-width:20rem">
                        <template #body="{ data }"><span class="text-sm text-muted-color">{{ ringkasMeta(data.meta) }}</span></template>
                    </Column>
                </DataTable>

                <div v-if="logs.last_page > 1" class="flex items-center justify-between mt-4">
                    <span class="text-sm text-muted-color">Halaman {{ logs.current_page }} dari {{ logs.last_page }} · {{ logs.total }} catatan</span>
                    <div class="flex gap-2">
                        <Button icon="pi pi-chevron-left" :disabled="logs.current_page <= 1" @click="pindahHalaman(logs.current_page - 1)" />
                        <Button icon="pi pi-chevron-right" :disabled="logs.current_page >= logs.last_page" @click="pindahHalaman(logs.current_page + 1)" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
