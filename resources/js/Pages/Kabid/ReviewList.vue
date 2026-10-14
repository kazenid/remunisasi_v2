<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';
import { FilterMatchMode } from '@primevue/core/api';
import { namaBulan } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tugas: { type: Array, default: () => [] },
});

const toast = useToast();
const page = usePage();

const filters = ref({
    global: { value: null, matchMode: FilterMatchMode.CONTAINS },
    katimker_nama: { value: null, matchMode: FilterMatchMode.EQUALS },
    jenis: { value: null, matchMode: FilterMatchMode.EQUALS },
    periode: { value: null, matchMode: FilterMatchMode.EQUALS },
    bukti: { value: null, matchMode: FilterMatchMode.EQUALS },
});

const rows = computed(() => props.tugas.map((t) => ({
    ...t,
    periode: String(t.tanggal).slice(0, 7),
    bukti: t.peringatan.length ? 'belum' : 'lengkap',
})));
const katimkerOptions = computed(() => [...new Set(props.tugas.map((t) => t.katimker_nama))].sort());
const jenisOptions = [{ value: 'utama', label: 'Utama' }, { value: 'tambahan', label: 'Tambahan' }];
const buktiOptions = [{ value: 'lengkap', label: 'Bukti lengkap' }, { value: 'belum', label: 'Bukti belum lengkap' }];
const periodeOptions = computed(() => [...new Set(rows.value.map((r) => r.periode))]
    .sort()
    .reverse()
    .map((p) => {
        const [y, m] = p.split('-');
        return { value: p, label: `${namaBulan[Number(m)]} ${y}` };
    }));
const adaFilter = computed(() => Object.values(filters.value).some((f) => f.value));

function resetFilter() {
    Object.values(filters.value).forEach((f) => { f.value = null; });
}

// Approve/reject sukses me-redirect ke halaman ini dengan flash `success`
// ('Tugas disetujui.'/'Tugas ditolak.'). Tampilkan satu toast dari flash
// (pola flash-only seperti TaskList); `flash.id` memastikan pesan identik
// beruntun tetap memicu watcher.
watch(
    () => page.props.flash?.id,
    () => {
        const flash = page.props.flash ?? {};
        if (flash.error) toast.add({ severity: 'error', summary: 'Gagal', detail: flash.error, life: 5000 });
        if (flash.success) toast.add({ severity: 'success', summary: 'Berhasil', detail: flash.success, life: 3000 });
    },
);

const jenisLabel = { utama: 'Utama', tambahan: 'Tambahan' };

function formatDurasi(menit) {
    const j = Math.floor(menit / 60);
    const m = menit % 60;
    return [j ? `${j} jam` : null, m ? `${m} menit` : null].filter(Boolean).join(' ') || '0 menit';
}

function formatTanggal(t) {
    return new Date(t).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

function nilai(row) {
    router.visit(route('kabid.penilaian.show', row.id));
}
</script>

<template>
    <Head title="Penilaian Tugas" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12">
            <div class="card">
                <DataTable
                    :value="rows"
                    dataKey="id"
                    :paginator="true"
                    :rows="10"
                    :filters="filters"
                    :globalFilterFields="['judul', 'katimker_nama', 'jenis']"
                    responsiveLayout="scroll"
                    stripedRows
                >
                    <template #header>
                        <div class="flex flex-wrap justify-between items-center gap-4">
                            <div>
                                <h4 class="m-0 font-semibold text-xl">Penilaian Tugas</h4>
                                <span class="text-muted-color text-sm">Tugas Katimker binaan Anda yang sedang dalam proses penilaian.</span>
                            </div>
                            <IconField>
                                <InputIcon><i class="pi pi-search" /></InputIcon>
                                <InputText v-model="filters['global'].value" placeholder="Cari..." />
                            </IconField>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 mt-4">
                            <Select v-model="filters.katimker_nama.value" :options="katimkerOptions" placeholder="Semua Katimker" showClear filter class="w-56" />
                            <Select v-model="filters.periode.value" :options="periodeOptions" optionLabel="label" optionValue="value" placeholder="Semua periode" showClear class="w-48" />
                            <Select v-model="filters.jenis.value" :options="jenisOptions" optionLabel="label" optionValue="value" placeholder="Semua jenis" showClear class="w-44" />
                            <Select v-model="filters.bukti.value" :options="buktiOptions" optionLabel="label" optionValue="value" placeholder="Semua bukti" showClear class="w-52" />
                            <Button v-if="adaFilter" label="Reset" icon="pi pi-filter-slash" severity="secondary" text size="small" @click="resetFilter" />
                        </div>
                    </template>

                    <template #empty>
                        <div class="text-center text-muted-color py-6">{{ tugas.length ? 'Tidak ada tugas yang cocok dengan filter.' : 'Tidak ada tugas dalam proses penilaian.' }}</div>
                    </template>

                    <Column field="tanggal" header="Tanggal" sortable style="min-width:9rem">
                        <template #body="{ data }">{{ formatTanggal(data.tanggal) }}</template>
                    </Column>
                    <Column field="katimker_nama" header="Katimker" sortable style="min-width:16rem" />
                    <Column field="judul" header="Kegiatan" sortable style="min-width:16rem" />
                    <Column field="jenis" header="Jenis" sortable style="min-width:8rem">
                        <template #body="{ data }">{{ jenisLabel[data.jenis] }}</template>
                    </Column>
                    <Column header="Diajukan" style="min-width:9rem">
                        <template #body="{ data }">{{ formatDurasi(data.durasi_diajukan_menit) }}</template>
                    </Column>
                    <Column header="Bukti" style="min-width:7rem">
                        <template #body="{ data }">
                            <span class="inline-flex items-center gap-1 text-muted-color"><i class="pi pi-paperclip" /> {{ data.attachments_count }}</span>
                            <i
                                v-if="data.peringatan.length"
                                :title="data.peringatan.join(' ')"
                                class="pi pi-exclamation-triangle text-orange-500 ml-2"
                                aria-label="Bukti belum lengkap"
                            />
                        </template>
                    </Column>
                    <Column :exportable="false" header="Aksi" style="min-width:12rem">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <Button label="Detail" icon="pi pi-eye" size="small" severity="secondary" outlined @click="nilai(data)" />
                                <NilaiTugas :tugas="data" size="small" />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>
</template>
