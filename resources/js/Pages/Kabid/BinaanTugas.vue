<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { FilterMatchMode } from '@primevue/core/api';
import { formatDurasi, formatTanggal, jenisLabel, namaBulan, statusMeta } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    katimker: { type: Object, required: true },
    tugas: { type: Array, default: () => [] },
});

// Filter & pencarian dilakukan di klien (daftar sudah dibatasi ke satu Katimker).
const filters = ref({
    global: { value: null, matchMode: FilterMatchMode.CONTAINS },
    status: { value: null, matchMode: FilterMatchMode.EQUALS },
    jenis: { value: null, matchMode: FilterMatchMode.EQUALS },
    periode: { value: null, matchMode: FilterMatchMode.EQUALS },
});

const rows = computed(() => props.tugas.map((t) => ({ ...t, periode: `${t.tahun}-${String(t.bulan).padStart(2, '0')}` })));

const statusOptions = Object.entries(statusMeta).map(([value, m]) => ({ value, label: m.label }));
const jenisOptions = Object.entries(jenisLabel).map(([value, label]) => ({ value, label }));
const periodeOptions = computed(() => [...new Set(rows.value.map((r) => r.periode))]
    .sort()
    .reverse()
    .map((p) => {
        const [y, m] = p.split('-');
        return { value: p, label: `${namaBulan[Number(m)]} ${y}` };
    }));

const adaFilter = computed(() => !!(filters.value.global.value || filters.value.status.value || filters.value.jenis.value || filters.value.periode.value));

function reset() {
    filters.value.global.value = null;
    filters.value.status.value = null;
    filters.value.jenis.value = null;
    filters.value.periode.value = null;
}

function detail(row) {
    router.visit(route('kabid.penilaian.show', row.id));
}
</script>

<template>
    <Head :title="`Tugas ${katimker.nama}`" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12">
            <div class="card">
                <DataTable
                    :value="rows"
                    dataKey="id"
                    v-model:filters="filters"
                    :globalFilterFields="['judul', 'uraian']"
                    :paginator="true"
                    :rows="10"
                    responsiveLayout="scroll"
                    stripedRows
                >
                    <template #header>
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h4 class="m-0 font-semibold text-xl">Tugas {{ katimker.nama }}</h4>
                                    <span class="text-muted-color text-sm">Seluruh tugas Katimker binaan ini ({{ tugas.length }} tugas).</span>
                                </div>
                                <Button label="Kembali ke Rekap" icon="pi pi-arrow-left" severity="secondary" size="small" @click="router.visit(route('kabid.rekap.index'))" />
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <IconField>
                                    <InputIcon><i class="pi pi-search" /></InputIcon>
                                    <InputText v-model="filters.global.value" placeholder="Cari kegiatan / uraian..." />
                                </IconField>
                                <Select v-model="filters.periode.value" :options="periodeOptions" optionLabel="label" optionValue="value" placeholder="Semua periode" showClear class="w-48" />
                                <Select v-model="filters.status.value" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Semua status" showClear class="w-48" />
                                <Select v-model="filters.jenis.value" :options="jenisOptions" optionLabel="label" optionValue="value" placeholder="Semua jenis" showClear class="w-44" />
                                <Button v-if="adaFilter" label="Reset" icon="pi pi-filter-slash" severity="secondary" text size="small" @click="reset" />
                            </div>
                        </div>
                    </template>

                    <template #empty>
                        <div class="text-center text-muted-color py-6">{{ tugas.length ? 'Tidak ada tugas yang cocok dengan filter.' : 'Katimker ini belum memiliki tugas.' }}</div>
                    </template>

                    <Column field="tanggal" header="Tanggal" sortable style="min-width:9rem">
                        <template #body="{ data }">{{ formatTanggal(data.tanggal) }}</template>
                    </Column>
                    <Column field="judul" header="Kegiatan" sortable style="min-width:16rem" />
                    <Column field="jenis" header="Jenis" sortable style="min-width:8rem">
                        <template #body="{ data }">{{ jenisLabel[data.jenis] }}</template>
                    </Column>
                    <Column header="Diajukan" style="min-width:9rem">
                        <template #body="{ data }">{{ formatDurasi(data.durasi_diajukan_menit) }}</template>
                    </Column>
                    <Column header="Disetujui" style="min-width:9rem">
                        <template #body="{ data }">
                            <span v-if="data.durasi_disetujui_menit !== null">{{ formatDurasi(data.durasi_disetujui_menit) }}</span>
                            <span v-else class="text-muted-color">—</span>
                        </template>
                    </Column>
                    <Column field="status" header="Status" sortable style="min-width:11rem">
                        <template #body="{ data }">
                            <Tag :value="statusMeta[data.status]?.label ?? data.status" :severity="statusMeta[data.status]?.severity" />
                        </template>
                    </Column>
                    <Column header="Bukti" style="min-width:6rem">
                        <template #body="{ data }">
                            <span class="inline-flex items-center gap-1 text-muted-color"><i class="pi pi-paperclip" /> {{ data.attachments_count }}</span>
                        </template>
                    </Column>
                    <Column :exportable="false" header="Aksi" style="min-width:8rem">
                        <template #body="{ data }">
                            <Button label="Detail" icon="pi pi-eye" size="small" severity="secondary" outlined @click="detail(data)" />
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>
</template>
