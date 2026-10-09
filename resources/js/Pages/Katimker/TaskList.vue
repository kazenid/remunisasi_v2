<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { ref, watch } from 'vue';

defineOptions({ layout: AppLayout });

defineProps({
    tugas: { type: Array, default: () => [] },
});

const toast = useToast();
const confirm = useConfirm();
const page = usePage();

// Toast digerakkan oleh flash server (sumber kebenaran), bukan callback onSuccess Inertia.
// Alasan: pengajuan yang gagal mengembalikan back()->with('error', ...) — sebuah redirect
// 302→200 yang dianggap "sukses" oleh Inertia. Bila toast sukses dipicu onSuccess, ia akan
// menyala bersamaan dengan flash error → dua notifikasi. Dengan hanya mengandalkan flash:
// gagal → satu toast error, sukses → satu toast sukses. Tanpa `immediate` agar tidak
// memunculkan toast "basi" dari kunjungan sebelumnya.
watch(
    () => page.props.flash?.error,
    (msg) => {
        if (msg) toast.add({ severity: 'error', summary: 'Gagal', detail: msg, life: 5000 });
    },
);

watch(
    () => page.props.flash?.success,
    (msg) => {
        if (msg) toast.add({ severity: 'success', summary: 'Berhasil', detail: msg, life: 3000 });
    },
);

const filters = ref({ global: { value: null, matchMode: 'contains' } });

const statusMeta = {
    menunggu_bukti: { label: 'Menunggu Bukti', severity: 'warn' },
    draf: { label: 'Draf', severity: 'secondary' },
    diajukan: { label: 'Diajukan', severity: 'info' },
    disetujui: { label: 'Disetujui', severity: 'success' },
    ditolak: { label: 'Ditolak', severity: 'danger' },
};

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

function konfirmasiAjukan(row) {
    confirm.require({
        message: `Ajukan tugas "${row.judul}" untuk dinilai Kabid? Setelah diajukan, tugas tidak dapat diubah.`,
        header: 'Ajukan Tugas',
        icon: 'pi pi-send',
        rejectLabel: 'Batal',
        acceptLabel: 'Ajukan',
        accept: () => {
            // Jangan toast di onSuccess: pengajuan gagal (tanpa selfie) tetap redirect 302→200
            // yang dianggap sukses oleh Inertia. Toast sukses/gagal digerakkan flash server
            // (watcher di atas) agar pengajuan gagal hanya memunculkan satu toast error.
            router.post(route('katimker.tugas.submit', row.id), {}, {
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
                    :value="tugas"
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
                            <div class="flex items-center gap-3">
                                <IconField>
                                    <InputIcon><i class="pi pi-search" /></InputIcon>
                                    <InputText v-model="filters['global'].value" placeholder="Cari tugas..." />
                                </IconField>
                                <Button label="Tugas Baru" icon="pi pi-plus" @click="buatBaru" />
                            </div>
                        </div>
                    </template>

                    <template #empty>
                        <div class="text-center text-muted-color py-6">Belum ada tugas. Klik "Tugas Baru" untuk mulai.</div>
                    </template>

                    <Column field="tanggal" header="Tanggal" sortable style="min-width:9rem">
                        <template #body="{ data }">{{ formatTanggal(data.tanggal) }}</template>
                    </Column>
                    <Column field="judul" header="Judul" sortable style="min-width:16rem">
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
                                <span v-if="data.status === 'ditolak' && data.catatan_penilai" class="text-xs text-red-500">
                                    <i class="pi pi-info-circle" /> {{ data.catatan_penilai }}
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
                    <Column :exportable="false" header="Aksi" style="min-width:12rem">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <Button
                                    v-if="['draf', 'ditolak', 'menunggu_bukti'].includes(data.status)"
                                    icon="pi pi-send" label="Ajukan" size="small" @click="konfirmasiAjukan(data)"
                                />
                                <Button
                                    v-if="['draf', 'ditolak', 'menunggu_bukti'].includes(data.status)"
                                    icon="pi pi-pencil" outlined rounded @click="edit(data)"
                                />
                                <Button
                                    v-if="data.status === 'draf'"
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
