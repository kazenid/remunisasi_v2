<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import DurationInput from '@/Components/form/DurationInput.vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tugas: { type: Object, required: true },
});

const toast = useToast();
const page = usePage();

// Pisahkan lampiran: selfie ditampilkan sebagai foto berframe, dokumen sebagai link.
const selfies = computed(() => props.tugas.attachments.filter((a) => a.tipe === 'foto_selfie'));
const dokumen = computed(() => props.tugas.attachments.filter((a) => a.tipe !== 'foto_selfie'));

// Modal (lightbox) untuk melihat selfie ukuran penuh di dalam aplikasi (bukan tab baru).
const selfieDialog = ref(false);
const selfieAktif = ref(null);

function bukaSelfie(att) {
    selfieAktif.value = att;
    selfieDialog.value = true;
}

const jenisLabel = { utama: 'Tugas Utama', tambahan: 'Tugas Tambahan' };

function formatDurasi(menit) {
    if (menit === null || menit === undefined) return '—';
    const j = Math.floor(menit / 60);
    const m = menit % 60;
    return [j ? `${j} jam` : null, m ? `${m} menit` : null].filter(Boolean).join(' ') || '0 menit';
}

// Form approve: default durasi disetujui = durasi diajukan.
const approveForm = useForm({
    durasi_jam: Math.floor(props.tugas.durasi_diajukan_menit / 60),
    durasi_menit: props.tugas.durasi_diajukan_menit % 60,
    alasan: '',
    catatan: '',
    jenis: props.tugas.jenis,
    alasan_jenis: '',
});

const rejectForm = useForm({ catatan: '' });

// Form ubah jenis (aksi terpisah).
const jenisOptions = [
    { label: 'Tugas Utama', value: 'utama' },
    { label: 'Tugas Tambahan', value: 'tambahan' },
];
const jenisForm = useForm({ jenis: props.tugas.jenis, alasan: '' });
const jenisBerubahApprove = computed(() => approveForm.jenis !== props.tugas.jenis);

function ubahJenis() {
    jenisForm.post(route('kabid.penilaian.jenis', props.tugas.id), {
        preserveScroll: true,
        onSuccess: () => toast.add({ severity: 'success', summary: 'Jenis diperbarui', life: 3000 }),
    });
}

const totalDisetujui = computed(() => (approveForm.durasi_jam ?? 0) * 60 + (approveForm.durasi_menit ?? 0));
const dikurangi = computed(() => totalDisetujui.value < props.tugas.durasi_diajukan_menit);

function setujui() {
    approveForm.post(route('kabid.penilaian.approve', props.tugas.id), {
        onSuccess: () => toast.add({ severity: 'success', summary: 'Disetujui', detail: 'Penilaian tersimpan.', life: 3000 }),
    });
}

function tolak() {
    rejectForm.post(route('kabid.penilaian.reject', props.tugas.id), {
        onSuccess: () => toast.add({ severity: 'success', summary: 'Ditolak', detail: 'Tugas dikembalikan ke Katimker.', life: 3000 }),
    });
}

// Pantau `flash.id` (nonce per-request) agar pesan gagal identik beruntun tetap memicu toast.
watch(
    () => page.props.flash?.id,
    () => {
        const msg = page.props.flash?.error;
        if (msg) toast.add({ severity: 'error', summary: 'Gagal', detail: msg, life: 5000 });
    },
);
</script>

<template>
    <Head title="Nilai Tugas" />

    <div class="grid grid-cols-12 gap-8">
        <!-- Rincian tugas -->
        <div class="col-span-12 xl:col-span-7">
            <div class="card">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="m-0 font-semibold text-xl">Rincian Tugas</h4>
                    <Button icon="pi pi-arrow-left" label="Kembali" severity="secondary" size="small" @click="router.visit(route('kabid.penilaian.index'))" />
                </div>

                <dl class="grid grid-cols-12 gap-y-4">
                    <dt class="col-span-4 text-muted-color">Katimker</dt>
                    <dd class="col-span-8 font-medium text-surface-900 dark:text-surface-0">{{ tugas.katimker_nama }}</dd>

                    <dt class="col-span-4 text-muted-color">Tanggal</dt>
                    <dd class="col-span-8">{{ new Date(tugas.tanggal).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) }}</dd>

                    <dt class="col-span-4 text-muted-color">Jenis</dt>
                    <dd class="col-span-8"><Tag :value="jenisLabel[tugas.jenis]" :severity="tugas.jenis === 'utama' ? 'info' : 'warn'" /></dd>

                    <dt class="col-span-4 text-muted-color">Judul</dt>
                    <dd class="col-span-8 font-medium">{{ tugas.judul }}</dd>

                    <dt class="col-span-4 text-muted-color">Uraian</dt>
                    <dd class="col-span-8 whitespace-pre-line">{{ tugas.uraian || '—' }}</dd>

                    <dt class="col-span-4 text-muted-color">Durasi diajukan</dt>
                    <dd class="col-span-8 font-medium">{{ formatDurasi(tugas.durasi_diajukan_menit) }}</dd>

                    <dt class="col-span-4 text-muted-color">Koordinat</dt>
                    <dd class="col-span-8">
                        <span v-if="tugas.lat">{{ Number(tugas.lat).toFixed(5) }}, {{ Number(tugas.lng).toFixed(5) }}</span>
                        <span v-else class="text-muted-color">Tidak tersedia</span>
                    </dd>
                </dl>

                <Divider />

                <h5 class="m-0 mb-3 font-semibold">Bukti</h5>

                <p v-if="!tugas.attachments.length" class="text-muted-color">Tidak ada bukti.</p>

                <template v-else>
                    <!-- Bukti selfie: foto berframe, klik untuk perbesar di modal -->
                    <div v-if="selfies.length" class="mb-4">
                        <span class="block text-muted-color font-medium mb-2">Bukti Selfie</span>
                        <div class="flex flex-wrap gap-3">
                            <button
                                v-for="att in selfies"
                                :key="att.id"
                                type="button"
                                class="p-0 border-0 bg-transparent cursor-pointer"
                                :aria-label="`Perbesar ${att.nama_asli}`"
                                @click="bukaSelfie(att)"
                            >
                                <img
                                    :src="att.url"
                                    :alt="att.nama_asli"
                                    class="w-40 h-40 rounded-border object-cover border border-surface-200 dark:border-surface-700 hover:opacity-90 transition-opacity"
                                />
                            </button>
                        </div>
                    </div>

                    <!-- Dokumen: tetap sebagai link yang membuka tab baru -->
                    <div v-if="dokumen.length">
                        <span class="block text-muted-color font-medium mb-2">Dokumen</span>
                        <ul class="flex flex-col gap-2">
                            <li v-for="att in dokumen" :key="att.id" class="flex items-center gap-2">
                                <i class="pi pi-file-pdf text-red-500" />
                                <a :href="att.url" target="_blank" rel="noopener" class="text-primary hover:underline">{{ att.nama_asli }}</a>
                                <Tag value="Dokumen" severity="secondary" />
                            </li>
                        </ul>
                    </div>
                </template>
            </div>
        </div>

        <!-- Form penilaian -->
        <div class="col-span-12 xl:col-span-5">
            <!-- Klasifikasi (aksi terpisah) -->
            <div class="card">
                <h4 class="m-0 mb-4 font-semibold text-xl">Klasifikasi</h4>
                <div class="flex flex-col gap-3">
                    <SelectButton v-model="jenisForm.jenis" :options="jenisOptions" optionLabel="label" optionValue="value" :allowEmpty="false" />
                    <div v-if="jenisForm.jenis !== tugas.jenis" class="flex flex-col gap-2">
                        <label class="font-medium">Alasan Perubahan <span class="text-red-500">*</span></label>
                        <Textarea v-model="jenisForm.alasan" rows="2" autoResize :class="{ 'p-invalid': jenisForm.errors.alasan }" />
                        <small v-if="jenisForm.errors.alasan" class="text-red-500">{{ jenisForm.errors.alasan }}</small>
                    </div>
                    <Button
                        label="Simpan Klasifikasi" icon="pi pi-tag" severity="secondary"
                        :disabled="jenisForm.jenis === tugas.jenis" :loading="jenisForm.processing" @click="ubahJenis"
                    />
                </div>
            </div>

            <div class="card">
                <h4 class="m-0 mb-4 font-semibold text-xl">Setujui</h4>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Jam Disetujui</label>
                        <DurationInput v-model:jam="approveForm.durasi_jam" v-model:menit="approveForm.durasi_menit" />
                        <small class="text-muted-color">Maksimal sama dengan jam diajukan ({{ formatDurasi(tugas.durasi_diajukan_menit) }}).</small>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Jenis saat Menyetujui</label>
                        <SelectButton v-model="approveForm.jenis" :options="jenisOptions" optionLabel="label" optionValue="value" :allowEmpty="false" />
                        <div v-if="jenisBerubahApprove" class="flex flex-col gap-2 mt-1">
                            <label class="font-medium">Alasan Perubahan Jenis <span class="text-red-500">*</span></label>
                            <Textarea v-model="approveForm.alasan_jenis" rows="2" autoResize />
                        </div>
                    </div>

                    <div v-if="dikurangi" class="flex flex-col gap-2">
                        <label class="font-medium">Alasan Pengurangan <span class="text-red-500">*</span></label>
                        <Textarea v-model="approveForm.alasan" rows="2" autoResize :class="{ 'p-invalid': approveForm.errors.alasan }" />
                        <small class="text-muted-color">Wajib karena jam disetujui lebih kecil dari diajukan.</small>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Catatan <span class="text-muted-color font-normal">(opsional)</span></label>
                        <Textarea v-model="approveForm.catatan" rows="2" autoResize />
                    </div>

                    <Button label="Setujui" icon="pi pi-check" severity="success" :loading="approveForm.processing" @click="setujui" />
                </div>

                <Divider />

                <h4 class="m-0 mb-4 font-semibold text-xl">Tolak</h4>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Catatan Penolakan <span class="text-red-500">*</span></label>
                        <Textarea v-model="rejectForm.catatan" rows="3" autoResize :class="{ 'p-invalid': rejectForm.errors.catatan }" />
                        <small v-if="rejectForm.errors.catatan" class="text-red-500">{{ rejectForm.errors.catatan }}</small>
                    </div>
                    <Button label="Tolak" icon="pi pi-times" severity="danger" outlined :loading="rejectForm.processing" @click="tolak" />
                </div>
            </div>
        </div>
    </div>

    <!-- Lightbox selfie (in-app, bukan tab baru) -->
    <Dialog v-model:visible="selfieDialog" modal dismissableMask :header="selfieAktif?.nama_asli ?? 'Bukti Selfie'" :style="{ width: '90vw', maxWidth: '640px' }">
        <img v-if="selfieAktif" :src="selfieAktif.url" :alt="selfieAktif.nama_asli" class="w-full h-auto rounded-border" />
    </Dialog>
</template>
