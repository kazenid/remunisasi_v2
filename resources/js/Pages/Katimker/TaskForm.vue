<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import DurationInput from '@/Components/form/DurationInput.vue';
import SelfieCapture from '@/Components/form/SelfieCapture.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref, onBeforeUnmount } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tugas: { type: Object, default: null },
});

const toast = useToast();
const confirm = useConfirm();
const isEdit = computed(() => props.tugas !== null);

// Satu sumber angka batas ukuran, sinkron dengan rule backend
// (foto_selfie max:5120 = 5 MB, bukti max:10240 = 10 MB).
const MAKS_SELFIE_MB = 5;
const MAKS_BUKTI_MB = 10;

const jenisOptions = [
    { label: 'Tugas Utama', value: 'utama' },
    { label: 'Tugas Tambahan', value: 'tambahan' },
];

function initDate() {
    if (props.tugas?.tanggal) return new Date(props.tugas.tanggal);
    return new Date();
}

const form = useForm({
    tanggal: initDate(),
    jenis: props.tugas?.jenis ?? 'utama',
    judul: props.tugas?.judul ?? '',
    uraian: props.tugas?.uraian ?? '',
    durasi_jam: props.tugas ? Math.floor(props.tugas.durasi_diajukan_menit / 60) : 0,
    durasi_menit: props.tugas ? props.tugas.durasi_diajukan_menit % 60 : 0,
    lat: props.tugas?.lat ?? null,
    lng: props.tugas?.lng ?? null,
    akurasi: props.tugas?.akurasi ?? null,
    foto_selfie: [],
    dokumen: [],
    bukti: [],
});

// Catatan: kesalahan simpan/validasi form ditampilkan lewat callback `onError` submit
// (satu sumber toast). Jalur gagal simpan controller memakai `withErrors(...)` sehingga
// Inertia memanggil `onError`; tidak ada watcher flash error di sini agar tidak muncul
// dua toast pada kasus `withErrors()->with('error', ...)`, sekaligus mencegah toast
// error "basi" dari kunjungan sebelumnya saat form pertama kali dibuka.

// Lampiran selfie yang baru ditangkap (belum tersimpan).
// Simpan pasangan { file, previewUrl } agar thumbnail bisa ditampilkan sebelum submit,
// dan object URL dapat di-revoke saat dihapus / unmount (hindari memory leak).
const selfieBaru = ref([]);

// Sinkronkan field form dengan daftar File (object URL tidak ikut dikirim ke server).
function syncSelfieForm() {
    form.foto_selfie = selfieBaru.value.map((s) => s.file);
}

function onSelfie(file) {
    selfieBaru.value.push({ file, previewUrl: URL.createObjectURL(file) });
    syncSelfieForm();
}

function hapusSelfieBaru(idx) {
    const [dihapus] = selfieBaru.value.splice(idx, 1);
    if (dihapus) URL.revokeObjectURL(dihapus.previewUrl);
    syncSelfieForm();
}

onBeforeUnmount(() => {
    selfieBaru.value.forEach((s) => URL.revokeObjectURL(s.previewUrl));
});

function onDokumen(event) {
    form.dokumen = Array.from(event.files ?? []);
}

function toastTerlaluBesar(nama, maksMb) {
    toast.add({
        severity: 'error',
        summary: 'File terlalu besar',
        detail: `Berkas "${nama}" melebihi ${maksMb} MB dan tidak diikutsertakan. Ukuran maksimal ${maksMb} MB per berkas.`,
        life: 6000,
    });
}

// Penjaga ukuran sisi klien untuk field bukti: file oversize ditolak sebelum submit.
function onBukti(event) {
    const batas = MAKS_BUKTI_MB * 1024 * 1024;
    const diterima = [];
    for (const f of Array.from(event.files ?? [])) {
        if (f.size > batas) {
            toastTerlaluBesar(f.name, MAKS_BUKTI_MB);
        } else {
            diterima.push(f);
        }
    }
    form.bukti = diterima;
}

// PrimeVue FileUpload juga menolak file > maxFileSize lewat event ini.
function onBuktiError(maksMb = MAKS_BUKTI_MB) {
    toast.add({
        severity: 'error',
        summary: 'File terlalu besar',
        detail: `Ada berkas yang melebihi ${maksMb} MB. Ukuran maksimal ${maksMb} MB per berkas.`,
        life: 6000,
    });
}

function onSelfieTerlaluBesar({ name, maxMb }) {
    toast.add({
        severity: 'error',
        summary: 'File terlalu besar',
        detail: `Foto "${name}" melebihi ${maxMb} MB. Ukuran foto selfie maksimal ${maxMb} MB.`,
        life: 6000,
    });
}

function hapusBukti(idx) {
    form.bukti = form.bukti.filter((_, i) => i !== idx);
}

function ambilLokasi() {
    if (!navigator.geolocation) {
        toast.add({ severity: 'warn', summary: 'Lokasi', detail: 'Perangkat tidak mendukung geolokasi.', life: 3000 });
        return;
    }
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            form.lat = pos.coords.latitude;
            form.lng = pos.coords.longitude;
            form.akurasi = pos.coords.accuracy;
            toast.add({ severity: 'success', summary: 'Lokasi', detail: 'Koordinat terekam.', life: 2500 });
        },
        () => toast.add({ severity: 'warn', summary: 'Lokasi', detail: 'Izin lokasi ditolak (opsional).', life: 3000 }),
    );
}

function transform(data) {
    return {
        ...data,
        tanggal: data.tanggal instanceof Date
            ? data.tanggal.toISOString().slice(0, 10)
            : data.tanggal,
    };
}

function submit() {
    const opts = {
        forceFormData: true,
        onSuccess: () => toast.add({ severity: 'success', summary: 'Berhasil', detail: 'Draf tersimpan.', life: 3000 }),
        onError: (errors) => {
            const pesan = Object.values(errors ?? {})[0];
            toast.add({
                severity: 'error',
                summary: 'Gagal menyimpan',
                detail: pesan ?? 'Periksa isian yang ditandai merah.',
                life: 6000,
            });
        },
    };
    if (isEdit.value) {
        form.transform(transform).post(route('katimker.tugas.update', props.tugas.id), opts);
    } else {
        form.transform(transform).post(route('katimker.tugas.store'), opts);
    }
}

function hapusLampiranLama(att) {
    confirm.require({
        message: `Hapus lampiran "${att.nama_asli}"?`,
        header: 'Konfirmasi Hapus',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Hapus',
        rejectLabel: 'Batal',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('katimker.tugas.lampiran.destroy', att.id), {
                preserveScroll: true,
                onSuccess: () => toast.add({ severity: 'success', summary: 'Lampiran dihapus', life: 2500 }),
                onError: () => toast.add({ severity: 'error', summary: 'Gagal menghapus lampiran', detail: 'Coba lagi beberapa saat.', life: 5000 }),
            });
        },
    });
}
</script>

<template>
    <Head :title="isEdit ? 'Ubah Tugas' : 'Tugas Baru'" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12 xl:col-span-8">
            <div class="card">
                <h4 class="m-0 mb-6 font-semibold text-xl">{{ isEdit ? 'Ubah Tugas' : 'Catat Tugas Baru' }}</h4>

                <div class="flex flex-col gap-5">
                    <div class="grid grid-cols-12 gap-4">
                        <div class="col-span-12 md:col-span-6 flex flex-col gap-2">
                            <label class="font-medium">Tanggal Kegiatan</label>
                            <DatePicker v-model="form.tanggal" dateFormat="dd/mm/yy" showIcon fluid :class="{ 'p-invalid': form.errors.tanggal }" />
                            <small v-if="form.errors.tanggal" class="text-red-500">{{ form.errors.tanggal }}</small>
                        </div>
                        <div class="col-span-12 md:col-span-6 flex flex-col gap-2">
                            <label class="font-medium">Jenis Tugas</label>
                            <Select v-model="form.jenis" :options="jenisOptions" optionLabel="label" optionValue="value" fluid :class="{ 'p-invalid': form.errors.jenis }" />
                            <small v-if="form.errors.jenis" class="text-red-500">{{ form.errors.jenis }}</small>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Judul</label>
                        <InputText v-model="form.judul" placeholder="Judul kegiatan" :class="{ 'p-invalid': form.errors.judul }" />
                        <small v-if="form.errors.judul" class="text-red-500">{{ form.errors.judul }}</small>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Uraian <span class="text-muted-color font-normal">(opsional)</span></label>
                        <Textarea v-model="form.uraian" rows="3" autoResize placeholder="Uraian hasil/aktivitas" />
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Durasi Pengerjaan</label>
                        <DurationInput
                            v-model:jam="form.durasi_jam"
                            v-model:menit="form.durasi_menit"
                            :invalid="!!form.errors.durasi_menit"
                        />
                        <small v-if="form.errors.durasi_menit" class="text-red-500">{{ form.errors.durasi_menit }}</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-span-12 xl:col-span-4">
            <div class="card">
                <h5 class="m-0 mb-4 font-semibold text-lg">Bukti</h5>

                <div class="flex flex-col gap-2 mb-6">
                    <label class="font-medium">Foto Selfie <span class="text-red-500">*</span></label>
                    <span class="text-muted-color text-sm mb-2">Wajib saat pengajuan. Boleh ditambah sekarang. Maks {{ MAKS_SELFIE_MB }} MB per foto.</span>
                    <SelfieCapture :maxSizeMb="MAKS_SELFIE_MB" @captured="onSelfie" @tooLarge="onSelfieTerlaluBesar" />

                    <div v-if="selfieBaru.length" class="flex flex-wrap gap-3 mt-2">
                        <div v-for="(s, i) in selfieBaru" :key="i" class="relative">
                            <img
                                :src="s.previewUrl"
                                :alt="s.file.name"
                                class="w-20 h-20 rounded-border object-cover border border-surface-200 dark:border-surface-700"
                            />
                            <Button
                                icon="pi pi-times"
                                severity="danger"
                                rounded
                                text
                                size="small"
                                class="absolute -top-2 -right-2 bg-surface-0 dark:bg-surface-800 shadow"
                                aria-label="Hapus foto"
                                @click="hapusSelfieBaru(i)"
                            />
                        </div>
                    </div>
                    <small v-if="form.errors.foto_selfie" class="text-red-500">{{ form.errors.foto_selfie }}</small>
                </div>

                <div class="flex flex-col gap-2 mb-6">
                    <label class="font-medium">Bukti (Foto / Dokumen) <span class="text-muted-color font-normal">(opsional)</span></label>
                    <span class="text-muted-color text-sm mb-2">Bisa pilih beberapa file sekaligus: jpg, png, pdf, doc, docx, xls, xlsx (maks {{ MAKS_BUKTI_MB }} MB per file).</span>
                    <FileUpload
                        mode="basic"
                        name="bukti[]"
                        accept="image/jpeg,image/png,application/pdf,.doc,.docx,.xls,.xlsx"
                        :maxFileSize="MAKS_BUKTI_MB * 1024 * 1024"
                        :auto="false"
                        chooseLabel="Pilih Foto/Dokumen"
                        :invalidFileSizeMessage="`Ukuran berkas melebihi ${MAKS_BUKTI_MB} MB.`"
                        customUpload
                        multiple
                        @select="onBukti"
                        @error="onBuktiError()"
                    />

                    <div v-if="form.bukti.length" class="flex flex-wrap gap-2 mt-2">
                        <Chip v-for="(f, i) in form.bukti" :key="i" :label="f.name" removable @remove="hapusBukti(i)" />
                    </div>
                    <small v-if="form.errors.bukti" class="text-red-500">{{ form.errors.bukti }}</small>
                </div>

                <div class="flex flex-col gap-2">
                    <label class="font-medium">Koordinat <span class="text-muted-color font-normal">(opsional)</span></label>
                    <Button label="Rekam Lokasi" icon="pi pi-map-marker" severity="secondary" size="small" @click="ambilLokasi" />
                    <small v-if="form.lat" class="text-muted-color">{{ Number(form.lat).toFixed(5) }}, {{ Number(form.lng).toFixed(5) }}</small>
                </div>

                <div v-if="isEdit && tugas.attachments?.length" class="mt-6">
                    <label class="font-medium block mb-3">Lampiran tersimpan</label>
                    <ul class="flex flex-col gap-3">
                        <li v-for="att in tugas.attachments" :key="att.id" class="flex items-center justify-between gap-3">
                            <a
                                :href="att.url"
                                target="_blank"
                                rel="noopener"
                                class="flex items-center gap-3 min-w-0 text-surface-900 dark:text-surface-0 hover:text-primary"
                            >
                                <img
                                    v-if="att.is_image"
                                    :src="att.url"
                                    :alt="att.nama_asli"
                                    class="w-10 h-10 rounded-border object-cover shrink-0"
                                />
                                <span
                                    v-else
                                    class="w-10 h-10 rounded-border bg-surface-100 dark:bg-surface-800 flex items-center justify-center shrink-0"
                                >
                                    <i class="pi pi-file text-lg text-muted-color" />
                                </span>
                                <span class="flex flex-col min-w-0">
                                    <span class="truncate text-sm font-medium">{{ att.nama_asli }}</span>
                                    <span class="text-muted-color text-xs">{{ att.ukuran_label }}</span>
                                </span>
                            </a>
                            <Button icon="pi pi-trash" severity="danger" text rounded size="small" @click="hapusLampiranLama(att)" />
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-span-12">
            <div class="flex gap-2">
                <Button :label="isEdit ? 'Simpan Perubahan' : 'Simpan Draf'" icon="pi pi-check" :loading="form.processing" @click="submit" />
                <Button label="Batal" icon="pi pi-times" severity="secondary" @click="router.visit(route('katimker.tugas.index'))" />
            </div>
        </div>
    </div>
</template>
