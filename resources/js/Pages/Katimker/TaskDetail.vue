<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatDurasi, formatTanggal, jenisLabel, statusMeta } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tugas: { type: Object, required: true },
});

const selfies = computed(() => props.tugas.attachments.filter((a) => a.tipe === 'foto_selfie'));
const dokumen = computed(() => props.tugas.attachments.filter((a) => a.tipe !== 'foto_selfie'));

const dialog = ref(false);
const aktif = ref(null);
function perbesar(att) {
    aktif.value = att;
    dialog.value = true;
}

function waktu(iso) {
    return iso ? new Date(iso.replace(' ', 'T')).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}
</script>

<template>
    <Head title="Detail Tugas" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12 xl:col-span-8">
            <div class="card">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h4 class="m-0 font-semibold text-xl">Detail Tugas</h4>
                    <div class="flex gap-2">
                        <Button v-if="tugas.dapat_diubah" label="Ubah" icon="pi pi-pencil" size="small" @click="router.visit(route('katimker.tugas.edit', tugas.id))" />
                        <Button label="Kembali" icon="pi pi-arrow-left" severity="secondary" size="small" @click="router.visit(route('katimker.tugas.index'))" />
                    </div>
                </div>

                <dl class="grid grid-cols-12 gap-y-4 m-0">
                    <dt class="col-span-4 text-muted-color">Kegiatan</dt>
                    <dd class="col-span-8 m-0 font-medium">
                        {{ tugas.judul }}
                        <Tag v-if="tugas.penugasan" value="Penugasan" severity="warn" class="ml-2" />
                    </dd>

                    <dt class="col-span-4 text-muted-color">Tanggal</dt>
                    <dd class="col-span-8 m-0">{{ formatTanggal(tugas.tanggal) }}</dd>

                    <dt class="col-span-4 text-muted-color">Jenis</dt>
                    <dd class="col-span-8 m-0"><Tag :value="jenisLabel[tugas.jenis]" :severity="tugas.jenis === 'utama' ? 'info' : 'warn'" /></dd>

                    <dt class="col-span-4 text-muted-color">Uraian</dt>
                    <dd class="col-span-8 m-0 whitespace-pre-line">{{ tugas.uraian || '—' }}</dd>

                    <dt class="col-span-4 text-muted-color">Durasi diajukan</dt>
                    <dd class="col-span-8 m-0 font-medium">{{ formatDurasi(tugas.durasi_diajukan_menit) }}</dd>

                    <dt class="col-span-4 text-muted-color">Koordinat</dt>
                    <dd class="col-span-8 m-0">
                        <span v-if="tugas.lat">{{ Number(tugas.lat).toFixed(5) }}, {{ Number(tugas.lng).toFixed(5) }}</span>
                        <span v-else class="text-muted-color">Tidak tersedia</span>
                    </dd>
                </dl>

                <Divider />

                <h5 class="m-0 mb-3 font-semibold">Bukti</h5>
                <p v-if="!tugas.attachments.length" class="text-muted-color m-0">Tidak ada bukti.</p>
                <template v-else>
                    <div v-if="selfies.length" class="mb-4">
                        <span class="block text-muted-color font-medium mb-2">Bukti Selfie</span>
                        <div class="flex flex-wrap gap-3">
                            <button v-for="att in selfies" :key="att.id" type="button" class="p-0 border-0 bg-transparent cursor-pointer" :aria-label="`Perbesar ${att.nama_asli}`" @click="perbesar(att)">
                                <img :src="att.url" :alt="att.nama_asli" class="w-40 h-40 rounded-border object-cover border border-surface-200 dark:border-surface-700 hover:opacity-90 transition-opacity" />
                            </button>
                        </div>
                    </div>
                    <div v-if="dokumen.length">
                        <span class="block text-muted-color font-medium mb-2">Dokumen</span>
                        <ul class="flex flex-col gap-2 list-none p-0 m-0">
                            <li v-for="att in dokumen" :key="att.id" class="flex items-center gap-2">
                                <i class="pi pi-file text-muted-color" />
                                <a :href="att.url" target="_blank" rel="noopener" class="text-primary hover:underline">{{ att.nama_asli }}</a>
                            </li>
                        </ul>
                    </div>
                </template>
            </div>
        </div>

        <div class="col-span-12 xl:col-span-4">
            <div class="card">
                <h4 class="m-0 mb-4 font-semibold text-xl">Hasil Penilaian</h4>
                <div class="flex items-center gap-2 mb-4">
                    <span class="text-muted-color">Status:</span>
                    <Tag :value="statusMeta[tugas.status]?.label ?? tugas.status" :severity="statusMeta[tugas.status]?.severity" />
                </div>
                <dl class="grid grid-cols-12 gap-y-3 m-0">
                    <dt class="col-span-5 text-muted-color">Jam disetujui</dt>
                    <dd class="col-span-7 m-0 font-medium">{{ tugas.durasi_disetujui_menit !== null ? formatDurasi(tugas.durasi_disetujui_menit) : '—' }}</dd>

                    <dt class="col-span-5 text-muted-color">Keterangan penilai</dt>
                    <dd class="col-span-7 m-0 whitespace-pre-line">{{ tugas.catatan_penilai || '—' }}</dd>

                    <dt class="col-span-5 text-muted-color">Disimpan</dt>
                    <dd class="col-span-7 m-0">{{ waktu(tugas.diajukan_pada) }}</dd>

                    <dt class="col-span-5 text-muted-color">Dinilai</dt>
                    <dd class="col-span-7 m-0">{{ waktu(tugas.dinilai_pada) }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <Dialog v-model:visible="dialog" modal dismissableMask :header="aktif?.nama_asli ?? 'Bukti Selfie'" :style="{ width: '90vw', maxWidth: '640px' }">
        <img v-if="aktif" :src="aktif.url" :alt="aktif.nama_asli" class="w-full h-auto rounded-border" />
    </Dialog>
</template>
