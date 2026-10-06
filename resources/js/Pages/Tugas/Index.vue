<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tasks: { type: Object, default: () => ({ data: [] }) },
    currentPeriode: { type: Object, default: () => ({}) },
    allPeriodes: { type: Array, default: () => [] },
    rekap: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const showDialog = ref(false);
const showBuktiDialog = ref(false);
const activeBukti = ref(null);
const isEditing = ref(false);
const editingId = ref(null);
const geoLoading = ref(false);
const geoStatus = ref('');
const previewUrl = ref(null);

const filterPeriode = ref(props.filters.periode_id || props.currentPeriode?.id || '');
const filterKategori = ref(props.filters.kategori || '');
const filterStatus = ref(props.filters.status || '');

const form = useForm({
    tanggal: new Date().toISOString().slice(0, 10),
    kategori: 'utama',
    judul: '',
    deskripsi: '',
    jam_mulai: '07:30',
    jam_selesai: '14:00',
    durasi_jam: 6.5,
    is_draft: false,
    foto: null,
    latitude: null,
    longitude: null,
    accuracy: null,
});

const calculateDuration = () => {
    if (!form.jam_mulai || !form.jam_selesai) return;
    const [h1, m1] = form.jam_mulai.split(':').map(Number);
    const [h2, m2] = form.jam_selesai.split(':').map(Number);
    let diffMinutes = (h2 * 60 + m2) - (h1 * 60 + m1);
    if (diffMinutes < 0) diffMinutes += 24 * 60;
    form.durasi_jam = Math.max(0.25, Math.round((diffMinutes / 60) * 10) / 10);
};

const getGeoLocation = () => {
    if (!navigator.geolocation) {
        geoStatus.value = 'Browser tidak mendukung GPS';
        return;
    }
    geoLoading.value = true;
    geoStatus.value = 'Mendeteksi GPS...';
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            form.latitude = pos.coords.latitude;
            form.longitude = pos.coords.longitude;
            form.accuracy = Math.round(pos.coords.accuracy);
            geoStatus.value = `${pos.coords.latitude.toFixed(4)}, ${pos.coords.longitude.toFixed(4)} (±${Math.round(pos.coords.accuracy)}m)`;
            geoLoading.value = false;
        },
        (err) => {
            geoStatus.value = `Gagal GPS: ${err.message}`;
            geoLoading.value = false;
        },
        { enableHighAccuracy: true, timeout: 8000 }
    );
};

const onFileChange = (e) => {
    const file = e.target.files[0];
    if (file) {
        form.foto = file;
        previewUrl.value = URL.createObjectURL(file);
        if (!form.latitude) getGeoLocation();
    }
};

const openCreate = () => {
    isEditing.value = false;
    editingId.value = null;
    form.reset();
    form.tanggal = new Date().toISOString().slice(0, 10);
    form.kategori = 'utama';
    form.jam_mulai = '07:30';
    form.jam_selesai = '14:00';
    form.durasi_jam = 6.5;
    form.is_draft = false;
    form.foto = null;
    previewUrl.value = null;
    geoStatus.value = '';
    showDialog.value = true;
};

const openEdit = (task) => {
    isEditing.value = true;
    editingId.value = task.id;
    form.tanggal = task.tanggal;
    form.kategori = task.kategori;
    form.judul = task.judul;
    form.deskripsi = task.deskripsi || '';
    form.jam_mulai = task.jam_mulai || '07:30';
    form.jam_selesai = task.jam_selesai || '14:00';
    form.durasi_jam = Number(task.durasi_jam);
    form.is_draft = task.status === 'draft';
    form.foto = null;
    previewUrl.value = task.bukti?.[0]?.file_path || null;
    geoStatus.value = '';
    showDialog.value = true;
};

const viewBukti = (b) => {
    activeBukti.value = b;
    showBuktiDialog.value = true;
};

const submitTask = (asDraft = false) => {
    form.is_draft = asDraft;
    if (isEditing.value) {
        form.transform((d) => ({ ...d, _method: 'PUT' })).post(route('tugas.update', editingId.value), {
            forceFormData: true,
            onSuccess: () => { showDialog.value = false; },
        });
    } else {
        form.post(route('tugas.store'), {
            forceFormData: true,
            onSuccess: () => { showDialog.value = false; },
        });
    }
};

const deleteTask = (id) => {
    if (confirm('Hapus tugas ini?')) router.delete(route('tugas.destroy', id));
};

const applyFilter = () => {
    router.get(route('tugas.index'), {
        periode_id: filterPeriode.value,
        kategori: filterKategori.value,
        status: filterStatus.value,
    }, { preserveState: true });
};

const getStatusSeverity = (s) => {
    if (s && s.startsWith('disetujui')) return 'success';
    if (s === 'diajukan') return 'info';
    if (s === 'revisi') return 'warn';
    if (s === 'ditolak') return 'danger';
    return 'secondary';
};

const formatStatus = (s) => {
    const map = {
        disetujui_direktur: 'Disetujui Final',
        disetujui_atasan_katimker: 'Disetujui Atasan',
        disetujui_wadir: 'Disetujui Wadir',
        disetujui_kabid: 'Disetujui Kabid',
        disetujui_katker: 'Disetujui Katker',
        diajukan: 'Menunggu Verifikasi',
        revisi: 'Perlu Revisi',
        ditolak: 'Ditolak',
    };
    return map[s] || 'Draft';
};
</script>

<template>
    <Head title="Pencatatan Tugas Harian" />

    <div class="grid grid-cols-12 gap-6">
        <!-- Summary Header -->
        <div class="col-span-12">
            <div class="card mb-0">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold text-surface-900 dark:text-surface-0 mb-1">Log Tugas Harian</h2>
                        <p class="text-xs text-muted-color">Catat aktivitas harian periode {{ currentPeriode?.nama || '-' }}.</p>
                    </div>
                    <Button label="Catat Tugas Baru" icon="pi pi-plus" @click="openCreate" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6 pt-4 border-t border-surface-200 dark:border-surface-700">
                    <div class="p-3 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
                        <div class="text-xs text-blue-600 dark:text-blue-300 font-medium">Jam Utama Disetujui</div>
                        <div class="text-xl font-bold text-blue-900 dark:text-blue-100 mt-1">
                            {{ rekap.jam_utama_disetujui || 0 }} <span class="text-xs font-normal">/ {{ rekap.target_jam_utama || 150 }} Jam</span>
                        </div>
                    </div>
                    <div class="p-3 rounded-xl bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800">
                        <div class="text-xs text-purple-600 dark:text-purple-300 font-medium">Jam Tambahan Disetujui</div>
                        <div class="text-xl font-bold text-purple-900 dark:text-purple-100 mt-1">
                            {{ rekap.jam_tambahan_disetujui || 0 }} <span class="text-xs font-normal">/ {{ rekap.target_jam_tambahan || 15 }} Jam</span>
                        </div>
                    </div>
                    <div class="p-3 rounded-xl bg-teal-50 dark:bg-teal-900/20 border border-teal-200 dark:border-teal-800">
                        <div class="text-xs text-teal-600 dark:text-teal-300 font-medium">Total Capaian Kinerja</div>
                        <div class="text-xl font-bold text-teal-900 dark:text-teal-100 mt-1">
                            {{ rekap.total_persen_kinerja || 0 }}%
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="col-span-12">
            <div class="card p-4">
                <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-semibold mb-1 text-muted-color">Periode Kinerja</label>
                        <select v-model="filterPeriode" @change="applyFilter" class="w-full text-xs p-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800">
                            <option v-for="p in allPeriodes" :key="p.id" :value="p.id">{{ p.nama }} ({{ p.status }})</option>
                        </select>
                    </div>
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-semibold mb-1 text-muted-color">Kategori</label>
                        <select v-model="filterKategori" @change="applyFilter" class="w-full text-xs p-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800">
                            <option value="">Semua Kategori</option>
                            <option value="utama">Tugas Pokok / Utama</option>
                            <option value="tambahan">Tugas Tambahan</option>
                        </select>
                    </div>
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-semibold mb-1 text-muted-color">Status</label>
                        <select v-model="filterStatus" @change="applyFilter" class="w-full text-xs p-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800">
                            <option value="">Semua Status</option>
                            <option value="draft">Draft</option>
                            <option value="diajukan">Menunggu Verifikasi</option>
                            <option value="disetujui_katker">Disetujui Katker</option>
                            <option value="disetujui_kabid">Disetujui Kabid</option>
                            <option value="disetujui_wadir">Disetujui Wadir</option>
                            <option value="disetujui_direktur">Disetujui Final</option>
                            <option value="revisi">Revisi</option>
                            <option value="ditolak">Ditolak</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Table Tasks -->
        <div class="col-span-12">
            <div class="card p-0 overflow-hidden">
                <DataTable :value="tasks.data" responsiveLayout="scroll" :paginator="false" class="p-datatable-sm">
                    <template #empty>
                        <div class="text-center py-12 text-muted-color">
                            <i class="pi pi-file-edit text-4xl mb-3 block"></i>
                            Belum ada catatan tugas harian untuk filter yang dipilih.
                        </div>
                    </template>

                    <Column field="tanggal" header="Tanggal" style="width: 120px">
                        <template #body="{ data }">
                            <span class="font-medium text-xs">{{ data.tanggal }}</span>
                        </template>
                    </Column>

                    <Column field="kategori" header="Kategori" style="width: 130px">
                        <template #body="{ data }">
                            <Tag :value="data.kategori === 'utama' ? 'Tugas Utama' : 'Tambahan'" :severity="data.kategori === 'utama' ? 'info' : 'secondary'" />
                        </template>
                    </Column>

                    <Column field="judul" header="Uraian Aktivitas">
                        <template #body="{ data }">
                            <div class="font-semibold text-sm">{{ data.judul }}</div>
                            <div class="text-xs text-muted-color mt-0.5 line-clamp-1">{{ data.deskripsi || '-' }}</div>
                            <div v-if="data.alasan_tolak_revisi" class="text-xs text-red-500 font-medium mt-1">
                                Catatan Revisi: {{ data.alasan_tolak_revisi }}
                            </div>
                        </template>
                    </Column>

                    <Column header="Jam & Durasi" style="width: 140px">
                        <template #body="{ data }">
                            <div class="text-xs font-semibold">{{ data.durasi_jam }} Jam</div>
                            <div class="text-[11px] text-muted-color">{{ data.jam_mulai || '-' }} s/d {{ data.jam_selesai || '-' }}</div>
                        </template>
                    </Column>

                    <Column header="Bukti Foto" style="width: 100px">
                        <template #body="{ data }">
                            <button v-if="data.bukti && data.bukti.length > 0" type="button" @click="viewBukti(data.bukti[0])" class="flex items-center gap-1 text-xs text-primary hover:underline font-medium">
                                <i class="pi pi-image"></i>
                                <span>Bukti</span>
                            </button>
                            <span v-else class="text-[11px] text-muted-color">-</span>
                        </template>
                    </Column>

                    <Column field="status" header="Status" style="width: 150px">
                        <template #body="{ data }">
                            <Tag :value="formatStatus(data.status)" :severity="getStatusSeverity(data.status)" />
                        </template>
                    </Column>

                    <Column header="Aksi" style="width: 100px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-1">
                                <button v-if="['draft', 'revisi'].includes(data.status)" @click="openEdit(data)" class="p-2 text-primary hover:bg-surface-100 dark:hover:bg-surface-800 rounded-lg">
                                    <i class="pi pi-pencil text-sm"></i>
                                </button>
                                <button v-if="['draft', 'revisi'].includes(data.status)" @click="deleteTask(data.id)" class="p-2 text-red-500 hover:bg-surface-100 dark:hover:bg-surface-800 rounded-lg">
                                    <i class="pi pi-trash text-sm"></i>
                                </button>
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>
    <!-- Dialog Form Input Tugas -->
    <Dialog v-model:visible="showDialog" :header="isEditing ? 'Ubah Tugas Harian' : 'Catat Tugas Harian'" modal :style="{ width: 'min(680px, calc(100vw - 32px))' }" :contentStyle="{ maxHeight: 'calc(100dvh - 10rem)', overflowY: 'auto' }" class="task-dialog">
        <form @submit.prevent="submitTask(false)" class="task-form pt-1">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="task-date" class="block text-xs font-semibold mb-1.5">Tanggal <span class="text-red-500">*</span></label>
                    <input id="task-date" type="date" v-model="form.tanggal" required class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20" />
                </div>
                <div>
                    <label for="task-category" class="block text-xs font-semibold mb-1.5">Kategori Tugas <span class="text-red-500">*</span></label>
                    <select id="task-category" v-model="form.kategori" required class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20">
                        <option value="utama">Tugas Pokok / Utama (50%)</option>
                        <option value="tambahan">Tugas Tambahan (10%)</option>
                    </select>
                </div>
            </div>

            <div>
                <label for="task-title" class="block text-xs font-semibold mb-1.5">Uraian / Judul Tugas <span class="text-red-500">*</span></label>
                <input id="task-title" type="text" v-model="form.judul" required placeholder="Contoh: Pelayanan Pasien" class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20" />
            </div>

            <div>
                <label for="task-description" class="block text-xs font-semibold mb-1.5">Deskripsi Tambahan</label>
                <textarea id="task-description" v-model="form.deskripsi" rows="3" placeholder="Catatan detail tugas..." class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20"></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label for="task-start" class="block text-xs font-semibold mb-1.5">Jam Mulai</label>
                    <input id="task-start" type="time" v-model="form.jam_mulai" @change="calculateDuration" class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20" />
                </div>
                <div>
                    <label for="task-end" class="block text-xs font-semibold mb-1.5">Jam Selesai</label>
                    <input id="task-end" type="time" v-model="form.jam_selesai" @change="calculateDuration" class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20" />
                </div>
                <div>
                    <label for="task-duration" class="block text-xs font-semibold mb-1.5">Durasi (Jam) <span class="text-red-500">*</span></label>
                    <input id="task-duration" type="number" step="0.25" v-model="form.durasi_jam" required class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 font-semibold focus:outline-none focus:ring-2 focus:ring-primary/20" />
                </div>
            </div>

            <div class="task-evidence p-4 rounded-xl bg-surface-50 dark:bg-surface-800/60 border border-surface-200 dark:border-surface-700 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold">Bukti Pelaksanaan (Foto & GPS)</span>
                    <button type="button" @click="getGeoLocation" class="text-xs text-primary flex items-center gap-1.5 hover:underline font-medium" :disabled="geoLoading">
                        <i :class="geoLoading ? 'pi pi-spin pi-spinner' : 'pi pi-map-marker'"></i>
                        <span>GPS</span>
                    </button>
                </div>
                <label for="task-photo" class="text-xs text-muted-color block">Unggah foto sebagai bukti kegiatan</label>
                <input id="task-photo" type="file" accept="image/*" @change="onFileChange" class="w-full text-xs" />
                <div v-if="geoStatus" class="text-[11px] text-muted-color">
                    <i class="pi pi-compass text-xs mr-1"></i> {{ geoStatus }}
                </div>
                <div v-if="previewUrl" class="mt-2">
                    <img :src="previewUrl" alt="Bukti" class="h-24 rounded-lg object-cover border border-surface-200" />
                </div>
            </div>

            <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-2 pt-3 border-t border-surface-200 dark:border-surface-700">
                <Button type="button" label="Batal" severity="secondary" text @click="showDialog = false" />
                <Button type="button" label="Simpan Draft" severity="secondary" outlined @click="submitTask(true)" :loading="form.processing" />
                <Button type="button" label="Ajukan Verifikasi" icon="pi pi-send" @click="submitTask(false)" :loading="form.processing" />
            </div>
        </form>
    </Dialog>

    <!-- Dialog View Bukti -->
    <Dialog v-model:visible="showBuktiDialog" header="Bukti Pelaksanaan Tugas" modal :style="{ width: 'min(500px, calc(100vw - 32px))' }" :contentStyle="{ maxHeight: 'calc(100dvh - 10rem)', overflowY: 'auto' }">
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

