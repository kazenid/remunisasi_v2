<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    periodes: { type: Object, default: () => ({ data: [] }) },
});

const showDialog = ref(false);
const isEditing = ref(false);
const editingId = ref(null);

const form = useForm({
    tahun: new Date().getFullYear(),
    bulan: new Date().getMonth() + 1,
    nama: '',
    tgl_cutoff: 10,
    tgl_cair: 15,
    target_jam_utama: 150,
    target_jam_tambahan: 15,
    bobot_kehadiran: 40,
    bobot_tugas_utama: 50,
    bobot_tugas_tambahan: 10,
    nominal_dasar: 3500000,
    status: 'aktif',
});

const monthNames = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

const updateNama = () => {
    if (!isEditing.value) {
        form.nama = `${monthNames[form.bulan - 1]} ${form.tahun}`;
    }
};

const openCreate = () => {
    isEditing.value = false;
    editingId.value = null;
    form.reset();
    form.tahun = new Date().getFullYear();
    form.bulan = new Date().getMonth() + 1;
    form.nama = `${monthNames[form.bulan - 1]} ${form.tahun}`;
    form.tgl_cutoff = 10;
    form.tgl_cair = 15;
    form.target_jam_utama = 150;
    form.target_jam_tambahan = 15;
    form.bobot_kehadiran = 40;
    form.bobot_tugas_utama = 50;
    form.bobot_tugas_tambahan = 10;
    form.nominal_dasar = 3500000;
    form.status = 'aktif';
    showDialog.value = true;
};

const openEdit = (p) => {
    isEditing.value = true;
    editingId.value = p.id;
    form.tahun = p.tahun;
    form.bulan = p.bulan;
    form.nama = p.nama;
    form.tgl_cutoff = p.tgl_cutoff;
    form.tgl_cair = p.tgl_cair;
    form.target_jam_utama = Number(p.target_jam_utama);
    form.target_jam_tambahan = Number(p.target_jam_tambahan);
    form.bobot_kehadiran = Number(p.bobot_kehadiran);
    form.bobot_tugas_utama = Number(p.bobot_tugas_utama);
    form.bobot_tugas_tambahan = Number(p.bobot_tugas_tambahan);
    form.nominal_dasar = Number(p.nominal_dasar);
    form.status = p.status;
    showDialog.value = true;
};

const submitForm = () => {
    if (isEditing.value) {
        form.put(route('periode.update', editingId.value), {
            onSuccess: () => { showDialog.value = false; },
        });
    } else {
        form.post(route('periode.store'), {
            onSuccess: () => { showDialog.value = false; },
        });
    }
};

const formatCurrency = (val) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(val || 0);

const getStatusSeverity = (status) => {
    switch (status) {
        case 'aktif': return 'success';
        case 'terkunci': return 'warn';
        case 'selesai': return 'secondary';
        default: return 'info';
    }
};
</script>

<template>
    <Head title="Manajemen Periode Kinerja" />

    <div class="grid grid-cols-12 gap-6">
        <div class="col-span-12">
            <div class="card mb-0">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold text-surface-900 dark:text-surface-0">Master Periode Kinerja & Parameter</h2>
                        <p class="text-xs text-muted-color mt-1">
                            Atur jadwal cut-off bulanan, target jam (150 & 15 jam), bobot penilaian, dan status penguncian periode.
                        </p>
                    </div>
                    <Button label="Buat Periode Baru" icon="pi pi-plus" @click="openCreate" />
                </div>
            </div>
        </div>

        <div class="col-span-12">
            <div class="card p-0 overflow-hidden">
                <DataTable :value="periodes.data" responsiveLayout="scroll" :paginator="false" class="p-datatable-sm">
                    <template #empty>
                        <div class="text-center py-12 text-muted-color">
                            <i class="pi pi-calendar-times text-4xl mb-3 block"></i>
                            Belum ada periode kinerja yang didaftarkan.
                        </div>
                    </template>

                    <Column field="nama" header="Nama Periode" style="min-width: 180px">
                        <template #body="{ data }">
                            <div class="font-bold text-sm text-surface-900 dark:text-surface-0">{{ data.nama }}</div>
                            <div class="text-[11px] text-muted-color">Bulan {{ data.bulan }} / Tahun {{ data.tahun }}</div>
                        </template>
                    </Column>

                    <Column header="Target Jam" style="width: 170px">
                        <template #body="{ data }">
                            <div class="text-xs">Utama: <strong>{{ data.target_jam_utama }} Jam</strong></div>
                            <div class="text-xs text-muted-color">Tambahan: <strong>{{ data.target_jam_tambahan }} Jam</strong></div>
                        </template>
                    </Column>

                    <Column header="Bobot Formula" style="width: 180px">
                        <template #body="{ data }">
                            <div class="text-[11px]">Presensi: <strong>{{ data.bobot_kehadiran }}%</strong></div>
                            <div class="text-[11px]">Tugas Pokok: <strong>{{ data.bobot_tugas_utama }}%</strong></div>
                            <div class="text-[11px]">Tambahan: <strong>{{ data.bobot_tugas_tambahan }}%</strong></div>
                        </template>
                    </Column>

                    <Column header="Jadwal" style="width: 150px">
                        <template #body="{ data }">
                            <div class="text-xs">Cut-off: <strong>Tgl {{ data.tgl_cutoff }}</strong></div>
                            <div class="text-xs text-muted-color">Pencairan: <strong>Tgl {{ data.tgl_cair }}</strong></div>
                        </template>
                    </Column>

                    <Column header="Plafon Acuan" style="width: 150px">
                        <template #body="{ data }">
                            <div class="text-xs font-semibold">{{ formatCurrency(data.nominal_dasar) }}</div>
                        </template>
                    </Column>

                    <Column header="Status" style="width: 110px">
                        <template #body="{ data }">
                            <Tag :value="data.status.toUpperCase()" :severity="getStatusSeverity(data.status)" />
                        </template>
                    </Column>

                    <Column header="Aksi" style="width: 80px">
                        <template #body="{ data }">
                            <Button icon="pi pi-pencil" severity="secondary" text rounded size="small" @click="openEdit(data)" />
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>
    <!-- Dialog Create / Edit Periode -->
    <Dialog v-model:visible="showDialog" :header="isEditing ? 'Ubah Periode Kinerja' : 'Tambah Periode Kinerja'" modal :style="{ width: 'min(540px, calc(100vw - 32px))' }" :contentStyle="{ maxHeight: 'calc(100dvh - 10rem)', overflowY: 'auto' }">
        <form @submit.prevent="submitForm" class="space-y-4 pt-1">
            <div v-if="!isEditing" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold mb-1.5">Tahun <span class="text-red-500">*</span></label>
                    <input type="number" v-model="form.tahun" @change="updateNama" required class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 font-semibold focus:outline-none focus:ring-2 focus:ring-primary/20" />
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1.5">Bulan <span class="text-red-500">*</span></label>
                    <select v-model="form.bulan" @change="updateNama" required class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 font-semibold focus:outline-none focus:ring-2 focus:ring-primary/20">
                        <option v-for="(name, idx) in monthNames" :key="idx" :value="idx + 1">{{ name }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold mb-1.5">Nama Periode <span class="text-red-500">*</span></label>
                <input type="text" v-model="form.nama" required class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold mb-1.5">Target Jam Pokok (Jam) <span class="text-red-500">*</span></label>
                    <input type="number" step="1" v-model="form.target_jam_utama" required class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20" />
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1.5">Target Jam Tambahan (Jam) <span class="text-red-500">*</span></label>
                    <input type="number" step="1" v-model="form.target_jam_tambahan" required class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold mb-1.5">Tanggal Cut-off Verifikasi <span class="text-red-500">*</span></label>
                    <input type="number" min="1" max="31" v-model="form.tgl_cutoff" required class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20" />
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1.5">Status Periode <span class="text-red-500">*</span></label>
                    <select v-model="form.status" required class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20">
                        <option value="draft">Draft</option>
                        <option value="aktif">Aktif</option>
                        <option value="terkunci">Terkunci (Cut-off)</option>
                        <option value="selesai">Selesai (Sudah Cair)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold mb-1.5">Nominal Plafon Acuan (Rp) <span class="text-red-500">*</span></label>
                <input type="number" v-model="form.nominal_dasar" required class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20" />
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-surface-200 dark:border-surface-700">
                <Button type="button" label="Batal" severity="secondary" text @click="showDialog = false" />
                <Button type="submit" :label="isEditing ? 'Simpan Perubahan' : 'Buat Periode'" icon="pi pi-check" :loading="form.processing" />
            </div>
        </form>
    </Dialog>
</template>

