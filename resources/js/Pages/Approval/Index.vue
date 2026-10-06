<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tasks: { type: Object, default: () => ({ data: [] }) },
    currentPeriode: { type: Object, default: () => ({}) },
    userRole: { type: String, default: '' },
    isAtasanKatimker: { type: Boolean, default: false },
    filters: { type: Object, default: () => ({}) },
});

const searchQuery = ref(props.filters.search || '');
const selectedTaskIds = ref([]);
const selectAll = ref(false);

const showApproveDialog = ref(false);
const showRevisiDialog = ref(false);
const showTolakDialog = ref(false);
const showBuktiDialog = ref(false);

const currentTask = ref(null);
const activeBukti = ref(null);

const approveForm = useForm({
    jam_disetujui: null,
    kategori_baru: '',
    catatan: '',
});

const revisiForm = useForm({
    catatan: '',
});

const tolakForm = useForm({
    catatan: '',
});

const toggleSelectAll = () => {
    if (selectAll.value) {
        selectedTaskIds.value = props.tasks.data.map(t => t.id);
    } else {
        selectedTaskIds.value = [];
    }
};

const handleSearch = () => {
    router.get(route('approval.index'), { search: searchQuery.value }, { preserveState: true });
};

const openApprove = (task) => {
    currentTask.value = task;
    approveForm.jam_disetujui = Number(task.durasi_jam);
    approveForm.kategori_baru = task.kategori;
    approveForm.catatan = '';
    showApproveDialog.value = true;
};

const submitApprove = () => {
    if (!currentTask.value) return;
    approveForm.post(route('approval.approve', currentTask.value.id), {
        onSuccess: () => { showApproveDialog.value = false; },
    });
};

const openRevisi = (task) => {
    currentTask.value = task;
    revisiForm.catatan = '';
    showRevisiDialog.value = true;
};

const submitRevisi = () => {
    if (!currentTask.value) return;
    revisiForm.post(route('approval.revisi', currentTask.value.id), {
        onSuccess: () => { showRevisiDialog.value = false; },
    });
};

const openTolak = (task) => {
    currentTask.value = task;
    tolakForm.catatan = '';
    showTolakDialog.value = true;
};

const submitTolak = () => {
    if (!currentTask.value) return;
    tolakForm.post(route('approval.tolak', currentTask.value.id), {
        onSuccess: () => { showTolakDialog.value = false; },
    });
};

const submitBulkApprove = () => {
    if (selectedTaskIds.value.length === 0) return;
    if (confirm(`Setujui ${selectedTaskIds.value.length} tugas terpilih secara bersamaan?`)) {
        router.post(route('approval.bulk'), {
            ids: selectedTaskIds.value,
        }, {
            onSuccess: () => {
                selectedTaskIds.value = [];
                selectAll.value = false;
            },
        });
    }
};

const viewBukti = (b) => {
    activeBukti.value = b;
    showBuktiDialog.value = true;
};

const formatRoleTitle = (role) => {
    if (props.isAtasanKatimker && role !== 'admin' && role !== 'direktur') {
        return 'Atasan Langsung Katimker';
    }
    const map = {
        katker: 'Katker (Tahap 1)',
        kepala_unit: 'Kepala Unit (Tahap 1)',
        kabid: 'Kabid (Tahap 2)',
        wadir: 'Wadir (Tahap 3)',
        direktur: 'Direktur (Final)',
        admin: 'Administrator',
    };
    return map[role] || 'Verifikator';
};
</script>

<template>
    <Head title="Verifikasi Kinerja Pegawai" />

    <div class="grid grid-cols-12 gap-6">
        <div class="col-span-12">
            <div class="card mb-0">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-xl font-bold text-surface-900 dark:text-surface-0">Verifikasi Kinerja Staf</h2>
                            <Tag :value="formatRoleTitle(userRole)" severity="info" />
                        </div>
                        <p class="text-xs text-muted-color mt-1">
                            Memeriksa dan menyetujui log tugas harian bawahan periode {{ currentPeriode?.nama || '-' }}.
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <Button 
                            v-if="selectedTaskIds.length > 0" 
                            :label="`Setujui Terpilih (${selectedTaskIds.length})`" 
                            icon="pi pi-check-circle" 
                            severity="success" 
                            @click="submitBulkApprove" 
                        />
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mt-6 pt-4 border-t border-surface-200 dark:border-surface-700">
                    <div class="flex items-center gap-2">
                        <input type="checkbox" id="selectAllCheckbox" v-model="selectAll" @change="toggleSelectAll" class="rounded border-surface-300 w-4 h-4 cursor-pointer" />
                        <label for="selectAllCheckbox" class="text-xs font-semibold cursor-pointer">Pilih Semua (Halaman Ini)</label>
                    </div>

                    <div class="flex items-center gap-2">
                        <input 
                            type="text" 
                            v-model="searchQuery" 
                            @keyup.enter="handleSearch" 
                            placeholder="Cari staf atau uraian tugas..." 
                            class="w-64 text-xs p-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800" 
                        />
                        <Button icon="pi pi-search" severity="secondary" size="small" @click="handleSearch" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Tasks Table -->
        <div class="col-span-12">
            <div class="card p-0 overflow-hidden">
                <DataTable :value="tasks.data" responsiveLayout="scroll" :paginator="false" class="p-datatable-sm">
                    <template #empty>
                        <div class="text-center py-12 text-muted-color">
                            <i class="pi pi-check-circle text-4xl text-green-500 mb-3 block"></i>
                            Tidak ada tugas yang menunggu verifikasi pada tingkat Anda saat ini.
                        </div>
                    </template>

                    <Column style="width: 40px">
                        <template #body="{ data }">
                            <input type="checkbox" :value="data.id" v-model="selectedTaskIds" class="rounded border-surface-300 w-4 h-4 cursor-pointer" />
                        </template>
                    </Column>

                    <Column header="Pegawai" style="width: 200px">
                        <template #body="{ data }">
                            <div class="font-semibold text-xs text-surface-900 dark:text-surface-0">{{ data.user?.name || '-' }}</div>
                            <div class="text-[11px] text-muted-color">{{ data.user?.nip || '-' }}</div>
                            <div class="text-[10px] text-primary mt-0.5">{{ data.user?.jabatan || 'Staf Pelaksana' }}</div>
                            <Tag v-if="data.level_saat_ini === 'atasan_katimker'" value="Ketua Tim Kerja" severity="warn" class="mt-1 text-[10px]" />
                        </template>
                    </Column>

                    <Column header="Tanggal & Jam" style="width: 130px">
                        <template #body="{ data }">
                            <div class="font-semibold text-xs">{{ data.tanggal }}</div>
                            <div class="text-[11px] text-muted-color">{{ data.durasi_jam }} Jam</div>
                        </template>
                    </Column>

                    <Column header="Uraian Tugas & Bukti">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2 mb-1">
                                <Tag :value="data.kategori === 'utama' ? 'Tugas Utama' : 'Tambahan'" :severity="data.kategori === 'utama' ? 'info' : 'secondary'" />
                            </div>
                            <div class="font-semibold text-sm">{{ data.judul }}</div>
                            <div class="text-xs text-muted-color mt-0.5 line-clamp-2">{{ data.deskripsi || '-' }}</div>

                            <div v-if="data.bukti && data.bukti.length > 0" class="flex items-center gap-2 mt-2">
                                <button type="button" @click="viewBukti(data.bukti[0])" class="inline-flex items-center gap-1.5 text-xs text-primary font-medium hover:underline bg-primary/10 px-2 py-1 rounded-md">
                                    <i class="pi pi-image text-xs"></i>
                                    <span>Lihat Foto Bukti</span>
                                    <span v-if="data.bukti[0].latitude" class="text-[10px] text-emerald-600 font-bold ml-1">(GPS OK)</span>
                                </button>
                            </div>
                        </template>
                    </Column>

                    <Column header="Aksi Verifikasi" style="width: 180px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <Button label="Setuju" icon="pi pi-check" severity="success" size="small" @click="openApprove(data)" />
                                <Button icon="pi pi-undo" severity="warn" size="small" outlined title="Minta Revisi" @click="openRevisi(data)" />
                                <Button icon="pi pi-times" severity="danger" size="small" outlined title="Tolak Tugas" @click="openTolak(data)" />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>

    <!-- Dialog Approve -->
    <Dialog v-model:visible="showApproveDialog" header="Verifikasi & Setujui Tugas" modal :style="{ width: 'min(480px, calc(100vw - 32px))' }" :contentStyle="{ maxHeight: 'calc(100dvh - 10rem)', overflowY: 'auto' }">
        <form @submit.prevent="submitApprove" class="space-y-4 pt-1">
            <div class="p-3.5 bg-surface-50 dark:bg-surface-800/80 rounded-xl text-xs space-y-1.5 border border-surface-200 dark:border-surface-700">
                <div><span class="text-muted-color">Pegawai:</span> <span class="font-semibold text-surface-900 dark:text-surface-100 ml-1">{{ currentTask?.user?.name }}</span></div>
                <div><span class="text-muted-color">Tugas:</span> <span class="font-semibold text-surface-900 dark:text-surface-100 ml-1">{{ currentTask?.judul }}</span></div>
                <div><span class="text-muted-color">Durasi Diajukan:</span> <span class="font-semibold text-primary ml-1">{{ currentTask?.durasi_jam }} Jam</span></div>
            </div>

            <div>
                <label class="block text-xs font-semibold mb-1.5">Durasi Disetujui (Jam) <span class="text-red-500">*</span></label>
                <input type="number" step="0.25" v-model="approveForm.jam_disetujui" required class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 font-bold focus:outline-none focus:ring-2 focus:ring-primary/20" />
            </div>

            <div v-if="userRole === 'katker' || userRole === 'kepala_unit'">
                <label class="block text-xs font-semibold mb-1.5">Kategori Tugas</label>
                <select v-model="approveForm.kategori_baru" class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20">
                    <option value="utama">Tugas Pokok / Utama (50%)</option>
                    <option value="tambahan">Tugas Tambahan (10%)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold mb-1.5">Catatan Verifikasi (Opsional)</label>
                <textarea v-model="approveForm.catatan" rows="2" placeholder="Catatan untuk pegawai..." class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-surface-200 dark:border-surface-700">
                <Button type="button" label="Batal" severity="secondary" text @click="showApproveDialog = false" />
                <Button type="submit" label="Setujui Tugas" icon="pi pi-check" severity="success" :loading="approveForm.processing" />
            </div>
        </form>
    </Dialog>

    <!-- Dialog Revisi -->
    <Dialog v-model:visible="showRevisiDialog" header="Minta Revisi Tugas" modal :style="{ width: 'min(480px, calc(100vw - 32px))' }" :contentStyle="{ maxHeight: 'calc(100dvh - 10rem)', overflowY: 'auto' }">
        <form @submit.prevent="submitRevisi" class="space-y-4 pt-1">
            <div>
                <label class="block text-xs font-semibold mb-1.5">Instruksi Revisi <span class="text-red-500">*</span></label>
                <textarea v-model="revisiForm.catatan" required rows="3" placeholder="Alasan minta revisi..." class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-surface-200 dark:border-surface-700">
                <Button type="button" label="Batal" severity="secondary" text @click="showRevisiDialog = false" />
                <Button type="submit" label="Kirim Revisi" icon="pi pi-undo" severity="warn" :loading="revisiForm.processing" />
            </div>
        </form>
    </Dialog>

    <!-- Dialog Tolak -->
    <Dialog v-model:visible="showTolakDialog" header="Tolak Tugas Kinerja" modal :style="{ width: 'min(480px, calc(100vw - 32px))' }" :contentStyle="{ maxHeight: 'calc(100dvh - 10rem)', overflowY: 'auto' }">
        <form @submit.prevent="submitTolak" class="space-y-4 pt-1">
            <div class="p-3 bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400 rounded-xl text-xs font-medium border border-red-200 dark:border-red-900/50">
                Tugas yang ditolak tidak akan dihitung dalam capaian jam insentif.
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1.5">Alasan Penolakan <span class="text-red-500">*</span></label>
                <textarea v-model="tolakForm.catatan" required rows="3" placeholder="Alasan penolakan..." class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-surface-200 dark:border-surface-700">
                <Button type="button" label="Batal" severity="secondary" text @click="showTolakDialog = false" />
                <Button type="submit" label="Tolak Tugas" icon="pi pi-times" severity="danger" :loading="tolakForm.processing" />
            </div>
        </form>
    </Dialog>

    <!-- Dialog Bukti Preview -->
    <Dialog v-model:visible="showBuktiDialog" header="Foto Bukti Tugas" modal :style="{ width: 'min(500px, calc(100vw - 32px))' }" :contentStyle="{ maxHeight: 'calc(100dvh - 10rem)', overflowY: 'auto' }">
        <div v-if="activeBukti" class="space-y-3 pt-1">
            <img :src="activeBukti.file_path" alt="Foto Bukti" class="w-full rounded-xl object-contain max-h-96 border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50" />
            <div class="p-3 bg-surface-50 dark:bg-surface-800/80 rounded-xl text-xs space-y-1 text-muted-color border border-surface-200 dark:border-surface-700">
                <div v-if="activeBukti.latitude && activeBukti.longitude" class="flex items-center gap-1.5 text-surface-800 dark:text-surface-200 font-semibold">
                    <i class="pi pi-map-marker text-red-500"></i>
                    <span>GPS: {{ activeBukti.latitude }}, {{ activeBukti.longitude }} (±{{ activeBukti.accuracy || '-' }}m)</span>
                </div>
                <div>Waktu Upload: {{ activeBukti.waktu_ambil || activeBukti.created_at || '-' }}</div>
            </div>
        </div>
    </Dialog>
</template>
