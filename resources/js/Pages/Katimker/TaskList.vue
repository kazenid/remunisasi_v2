<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref, watch } from 'vue';
import { FilterMatchMode } from '@primevue/core/api';
import { jenisLabel as jenisLabelMap, namaBulan, statusMeta } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tugas: { type: Array, default: () => [] },
});

const toast = useToast();
const confirm = useConfirm();
const page = usePage();

// Toast digerakkan oleh flash server (sumber kebenaran), bukan callback onSuccess Inertia.
// Alasan: pengajuan yang gagal mengembalikan back()->with('error', ...) — sebuah redirect
// 302→200 yang dianggap "sukses" oleh Inertia. Bila toast sukses dipicu onSuccess, ia akan
// menyala bersamaan dengan flash error → dua notifikasi. Dengan hanya mengandalkan flash:
// gagal → satu toast error, sukses → satu toast sukses.
//
// Pantau `flash.id` (nonce per-request dari HandleInertiaRequests): nilainya berubah tiap
// kunjungan sehingga dua pesan gagal yang IDENTIK beruntun tetap memicu watcher → toast
// selalu muncul. Bila kita memantau string pesan langsung, nilai yang sama tidak dianggap
// berubah dan toast kedua tak pernah muncul. Tanpa `immediate` agar tidak memunculkan toast
// "basi" dari kunjungan sebelumnya.
watch(
    () => page.props.flash?.id,
    () => {
        const flash = page.props.flash ?? {};
        if (flash.error) toast.add({ severity: 'error', summary: 'Gagal', detail: flash.error, life: 5000 });
        if (flash.success) toast.add({ severity: 'success', summary: 'Berhasil', detail: flash.success, life: 3000 });
    },
);

const filters = ref({
    global: { value: null, matchMode: FilterMatchMode.CONTAINS },
    status: { value: null, matchMode: FilterMatchMode.EQUALS },
    jenis: { value: null, matchMode: FilterMatchMode.EQUALS },
    periode: { value: null, matchMode: FilterMatchMode.EQUALS },
});

const rows = computed(() => props.tugas.map((t) => ({ ...t, periode: String(t.tanggal).slice(0, 7) })));
const statusOptions = Object.entries(statusMeta).map(([value, m]) => ({ value, label: m.label }));
const jenisOptions = Object.entries(jenisLabelMap).map(([value, label]) => ({ value, label }));
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

function detail(row) {
    router.visit(route('katimker.tugas.show', row.id));
}

const jenisLabel = { utama: 'Utama', tambahan: 'Tambahan' };

function formatDurasi(menit) {
    const j = Math.floor(menit / 60);
    const m = menit % 60;
    return [j ? `${j} jam` : null, m ? `${m} menit` : null].filter(Boolean).join(' ') || '0 menit';
}

function formatTanggal(t) {
    return new Date(t).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

function buatBaru() {
    router.visit(route('katimker.tugas.create'));
}

function edit(row) {
    router.visit(route('katimker.tugas.edit', row.id));
}

function konfirmasiHapus(row) {
    confirm.require({
        message: `Hapus tugas "${row.judul}"?`,
        header: 'Konfirmasi',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Batal',
        acceptLabel: 'Hapus',
        acceptClass: 'p-button-danger',
        accept: () => {
            // Toast sukses "Tugas dihapus." datang dari flash success server (lihat watcher).
            router.delete(route('katimker.tugas.destroy', row.id), {
                preserveScroll: true,
            });
        },
    });
}
</script>

<template>
    <Head title="Tugas Saya" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12">
            <div class="card">
                <DataTable
                    :value="rows"
                    dataKey="id"
                    :paginator="true"
                    :rows="10"
                    :filters="filters"
                    :globalFilterFields="['judul', 'jenis', 'status']"
                    responsiveLayout="scroll"
                    stripedRows
                >
                    <template #header>
                        <div class="flex flex-wrap justify-between items-center gap-4">
                            <h4 class="m-0 font-semibold text-xl">Tugas Saya</h4>
                            <Button label="Tugas Baru" icon="pi pi-plus" @click="buatBaru" />
                        </div>
                        <div class="flex flex-wrap items-center gap-3 mt-4">
                            <IconField>
                                <InputIcon><i class="pi pi-search" /></InputIcon>
                                <InputText v-model="filters['global'].value" placeholder="Cari kegiatan..." />
                            </IconField>
                            <Select v-model="filters.periode.value" :options="periodeOptions" optionLabel="label" optionValue="value" placeholder="Semua periode" showClear class="w-48" />
                            <Select v-model="filters.status.value" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Semua status" showClear class="w-48" />
                            <Select v-model="filters.jenis.value" :options="jenisOptions" optionLabel="label" optionValue="value" placeholder="Semua jenis" showClear class="w-44" />
                            <Button v-if="adaFilter" label="Reset" icon="pi pi-filter-slash" severity="secondary" text size="small" @click="resetFilter" />
                        </div>
                    </template>

                    <template #empty>
                        <div class="text-center text-muted-color py-6">{{ tugas.length ? 'Tidak ada tugas yang cocok dengan filter.' : 'Belum ada tugas. Klik "Tugas Baru" untuk mulai.' }}</div>
                    </template>

                    <Column field="tanggal" header="Tanggal" sortable style="min-width:9rem">
                        <template #body="{ data }">{{ formatTanggal(data.tanggal) }}</template>
                    </Column>
                    <Column field="judul" header="Kegiatan" sortable style="min-width:16rem">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <span>{{ data.judul }}</span>
                                <Tag v-if="data.dibuat_oleh_sikep_user_id" value="Penugasan" severity="warn" />
                            </div>
                        </template>
                    </Column>
                    <Column field="jenis" header="Jenis" sortable style="min-width:8rem">
                        <template #body="{ data }">{{ jenisLabel[data.jenis] }}</template>
                    </Column>
                    <Column header="Durasi Diajukan" style="min-width:10rem">
                        <template #body="{ data }">{{ formatDurasi(data.durasi_diajukan_menit) }}</template>
                    </Column>
                    <Column header="Disetujui" style="min-width:10rem">
                        <template #body="{ data }">
                            <span v-if="data.durasi_disetujui_menit !== null">{{ formatDurasi(data.durasi_disetujui_menit) }}</span>
                            <span v-else class="text-muted-color">—</span>
                        </template>
                    </Column>
                    <Column field="status" header="Status" sortable style="min-width:12rem">
                        <template #body="{ data }">
                            <div class="flex flex-col gap-1">
                                <Tag :value="statusMeta[data.status].label" :severity="statusMeta[data.status].severity" />
                                <span
                                    v-if="['revisi', 'ditolak'].includes(data.status) && data.catatan_penilai"
                                    class="text-xs"
                                    :class="data.status === 'ditolak' ? 'text-red-500' : 'text-orange-500'"
                                >
                                    <i class="pi pi-info-circle" /> {{ data.catatan_penilai }}
                                </span>
                                <span v-if="data.periode_terkunci && ['diajukan', 'revisi', 'menunggu_bukti'].includes(data.status)" class="text-xs text-muted-color">
                                    <i class="pi pi-lock" /> Periode sudah melewati cut-off
                                </span>
                            </div>
                        </template>
                    </Column>
                    <Column header="Bukti" style="min-width:6rem">
                        <template #body="{ data }">
                            <span class="inline-flex items-center gap-1 text-muted-color">
                                <i class="pi pi-paperclip" /> {{ data.attachments_count }}
                            </span>
                        </template>
                    </Column>
                    <Column :exportable="false" header="Aksi" style="min-width:10rem">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <Button
                                    v-if="!data.dapat_diubah"
                                    icon="pi pi-eye" label="Detail" size="small" severity="secondary" outlined @click="detail(data)"
                                />
                                <Button
                                    v-if="data.dapat_diubah"
                                    icon="pi pi-pencil" label="Ubah" size="small" outlined @click="edit(data)"
                                />
                                <Button
                                    v-if="data.dapat_dihapus"
                                    icon="pi pi-trash" outlined rounded severity="danger" @click="konfirmasiHapus(data)"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>
</template>
