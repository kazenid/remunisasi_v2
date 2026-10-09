<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { ref, watch } from 'vue';

defineOptions({ layout: AppLayout });

defineProps({
    tugas: { type: Array, default: () => [] },
});

const toast = useToast();
const page = usePage();

const filters = ref({ global: { value: null, matchMode: 'contains' } });

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
                    :value="tugas"
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
                                <span class="text-muted-color text-sm">Tugas yang diajukan Katimker binaan Anda, menunggu penilaian.</span>
                            </div>
                            <IconField>
                                <InputIcon><i class="pi pi-search" /></InputIcon>
                                <InputText v-model="filters['global'].value" placeholder="Cari..." />
                            </IconField>
                        </div>
                    </template>

                    <template #empty>
                        <div class="text-center text-muted-color py-6">Tidak ada tugas yang menunggu penilaian.</div>
                    </template>

                    <Column field="tanggal" header="Tanggal" sortable style="min-width:9rem">
                        <template #body="{ data }">{{ formatTanggal(data.tanggal) }}</template>
                    </Column>
                    <Column field="katimker_nama" header="Katimker" sortable style="min-width:16rem" />
                    <Column field="judul" header="Judul" sortable style="min-width:16rem" />
                    <Column field="jenis" header="Jenis" sortable style="min-width:8rem">
                        <template #body="{ data }">{{ jenisLabel[data.jenis] }}</template>
                    </Column>
                    <Column header="Diajukan" style="min-width:9rem">
                        <template #body="{ data }">{{ formatDurasi(data.durasi_diajukan_menit) }}</template>
                    </Column>
                    <Column header="Bukti" style="min-width:6rem">
                        <template #body="{ data }">
                            <span class="inline-flex items-center gap-1 text-muted-color"><i class="pi pi-paperclip" /> {{ data.attachments_count }}</span>
                        </template>
                    </Column>
                    <Column :exportable="false" header="Aksi" style="min-width:8rem">
                        <template #body="{ data }">
                            <Button label="Nilai" icon="pi pi-check-square" size="small" @click="nilai(data)" />
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>
</template>
