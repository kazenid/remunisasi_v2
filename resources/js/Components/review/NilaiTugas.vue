<script setup>
import DurationInput from '@/Components/form/DurationInput.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatDurasi } from '@/lib/format';

// Tombol penilaian tunggal: "Nilai" → dropdown Setujui / Revisi / Tolak →
// (warning bukti bila ada) → dialog konfirmasi dengan satu kolom keterangan.
const props = defineProps({
    tugas: { type: Object, required: true }, // { id, durasi_diajukan_menit, peringatan[] }
    size: { type: String, default: null },
});

const menu = ref(null);
const aksi = ref(null); // 'setujui' | 'revisi' | 'tolak'
const dialogWarning = ref(false);
const dialogKonfirmasi = ref(false);

const aksiMeta = {
    setujui: { label: 'Setujui', icon: 'pi pi-check', severity: 'success', route: 'kabid.penilaian.approve', konfirmasi: 'Ya, Setujui' },
    revisi: { label: 'Revisi', icon: 'pi pi-replay', severity: 'warn', route: 'kabid.penilaian.revisi', konfirmasi: 'Ya, Minta Revisi' },
    tolak: { label: 'Tolak', icon: 'pi pi-times', severity: 'danger', route: 'kabid.penilaian.reject', konfirmasi: 'Ya, Tolak' },
};

const items = Object.entries(aksiMeta).map(([key, m]) => ({
    label: m.label,
    icon: m.icon,
    command: () => pilih(key),
}));

const form = useForm({
    durasi_jam: 0,
    durasi_menit: 0,
    keterangan: '',
});

const meta = computed(() => (aksi.value ? aksiMeta[aksi.value] : null));
const peringatan = computed(() => props.tugas.peringatan ?? []);
const totalDisetujui = computed(() => (form.durasi_jam ?? 0) * 60 + (form.durasi_menit ?? 0));
const dikurangi = computed(() => aksi.value === 'setujui' && totalDisetujui.value < props.tugas.durasi_diajukan_menit);
const keteranganWajib = computed(() => aksi.value !== 'setujui' || dikurangi.value);
const keteranganKosong = computed(() => keteranganWajib.value && !form.keterangan.trim());

function pilih(key) {
    aksi.value = key;
    form.reset();
    form.clearErrors();
    form.durasi_jam = Math.floor(props.tugas.durasi_diajukan_menit / 60);
    form.durasi_menit = props.tugas.durasi_diajukan_menit % 60;

    if (peringatan.value.length) {
        dialogWarning.value = true;
    } else {
        dialogKonfirmasi.value = true;
    }
}

function lanjutDariWarning() {
    dialogWarning.value = false;
    dialogKonfirmasi.value = true;
}

function kirim() {
    form
        .transform((d) => (aksi.value === 'setujui' ? d : { keterangan: d.keterangan }))
        .post(route(meta.value.route, props.tugas.id), {
            preserveScroll: true,
            onSuccess: () => { dialogKonfirmasi.value = false; },
        });
}
</script>

<template>
    <Button
        type="button"
        label="Nilai"
        icon="pi pi-check-square"
        :size="size"
        aria-haspopup="true"
        @click="(e) => menu.toggle(e)"
    />
    <Menu ref="menu" :model="items" :popup="true" />

    <!-- Peringatan bukti/validasi -->
    <Dialog v-model:visible="dialogWarning" modal header="Bukti belum lengkap" :style="{ width: '95vw', maxWidth: '30rem' }">
        <div class="flex items-start gap-3">
            <i class="pi pi-exclamation-triangle text-3xl text-orange-500 mt-1"></i>
            <div>
                <p class="mt-0 mb-3">Tugas ini belum memenuhi kelengkapan bukti:</p>
                <ul class="m-0 pl-5 flex flex-col gap-1">
                    <li v-for="w in peringatan" :key="w">{{ w }}</li>
                </ul>
                <p class="mb-0 mt-4 text-muted-color text-sm">
                    Anda tetap dapat melanjutkan penilaian ({{ meta?.label }}), atau batalkan untuk memeriksa kembali.
                </p>
            </div>
        </div>
        <template #footer>
            <Button label="Batal" severity="secondary" text @click="dialogWarning = false" />
            <Button label="Tetap lanjut menilai" icon="pi pi-arrow-right" severity="warn" @click="lanjutDariWarning" />
        </template>
    </Dialog>

    <!-- Konfirmasi penilaian -->
    <Dialog v-model:visible="dialogKonfirmasi" modal :header="`Konfirmasi: ${meta?.label ?? ''}`" :style="{ width: '95vw', maxWidth: '32rem' }">
        <div class="flex flex-col gap-4">
            <template v-if="aksi === 'setujui'">
                <div class="flex flex-col gap-2">
                    <label class="font-medium">Jam Disetujui</label>
                    <DurationInput v-model:jam="form.durasi_jam" v-model:menit="form.durasi_menit" />
                    <small class="text-muted-color">Maksimal sama dengan jam diajukan ({{ formatDurasi(tugas.durasi_diajukan_menit) }}).</small>
                </div>
            </template>
            <Message v-else-if="aksi === 'revisi'" severity="info" :closable="false">
                Tugas dikembalikan ke Katimker dan dapat diajukan kembali setelah diperbaiki.
            </Message>
            <Message v-else-if="aksi === 'tolak'" severity="error" :closable="false">
                Penolakan bersifat final. Katimker tidak dapat mengajukan kembali tugas ini.
            </Message>

            <div class="flex flex-col gap-2">
                <label class="font-medium">
                    Keterangan
                    <span v-if="keteranganWajib" class="text-red-500">*</span>
                    <span v-else class="text-muted-color font-normal">(opsional)</span>
                </label>
                <Textarea v-model="form.keterangan" rows="3" autoResize :class="{ 'p-invalid': form.errors.keterangan }" />
                <small v-if="dikurangi" class="text-muted-color">Wajib karena jam disetujui lebih kecil dari diajukan.</small>
                <small v-if="form.errors.keterangan" class="text-red-500">{{ form.errors.keterangan }}</small>
            </div>
        </div>
        <template #footer>
            <Button label="Batal" severity="secondary" text @click="dialogKonfirmasi = false" />
            <Button
                :label="meta?.konfirmasi ?? 'Simpan'"
                :icon="meta?.icon"
                :severity="meta?.severity"
                :loading="form.processing"
                :disabled="keteranganKosong"
                @click="kirim"
            />
        </template>
    </Dialog>
</template>
