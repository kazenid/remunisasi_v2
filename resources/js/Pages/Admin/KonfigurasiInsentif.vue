<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { computed, watch } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    config: { type: Object, required: true },
});

const toast = useToast();
const page = usePage();

const form = useForm({
    target_utama_jam: props.config.target_utama_jam ?? 0,
    target_utama_menit_sisa: props.config.target_utama_menit_sisa ?? 0,
    target_tambahan_jam: props.config.target_tambahan_jam ?? 0,
    target_tambahan_menit_sisa: props.config.target_tambahan_menit_sisa ?? 0,
    bobot_kehadiran: props.config.bobot_kehadiran ?? 40,
    bobot_utama: props.config.bobot_utama ?? 50,
    bobot_tambahan: props.config.bobot_tambahan ?? 10,
    nominal_dasar: props.config.nominal_dasar ?? null,
});

const totalBobot = computed(() => (form.bobot_kehadiran ?? 0) + (form.bobot_utama ?? 0) + (form.bobot_tambahan ?? 0));
const bobotValid = computed(() => totalBobot.value === 100);

function simpan() {
    form.post(route('admin.insentif.update'), {
        preserveScroll: true,
        onSuccess: () => toast.add({ severity: 'success', summary: 'Tersimpan', detail: 'Konfigurasi insentif disimpan.', life: 3000 }),
    });
}

watch(
    () => page.props.flash?.error,
    (msg) => { if (msg) toast.add({ severity: 'error', summary: 'Gagal', detail: msg, life: 5000 }); },
    { immediate: true },
);
</script>

<template>
    <Head title="Konfigurasi Insentif" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12 xl:col-span-8">
            <div class="card">
                <h4 class="m-0 mb-2 font-semibold text-xl">Konfigurasi Insentif</h4>
                <Message severity="info" :closable="false" class="mb-6">
                    Parameter ini dipakai oleh halaman
                    <Link :href="route('admin.insentif.hitung.index')" class="font-medium text-primary underline">Hitung Insentif</Link>
                    untuk menyimulasikan dan memfinalkan insentif per pegawai.
                </Message>

                <h5 class="m-0 mb-3 font-semibold">Target Jam</h5>
                <div class="grid grid-cols-12 gap-4 mb-6">
                    <div class="col-span-12 md:col-span-6 flex flex-col gap-2">
                        <label class="font-medium">Target Tugas Utama / bulan</label>
                        <InputGroup>
                            <InputNumber v-model="form.target_utama_jam" :min="0" :max="1000" showButtons fluid />
                            <InputGroupAddon>jam</InputGroupAddon>
                            <InputNumber v-model="form.target_utama_menit_sisa" :min="0" :max="59" showButtons fluid />
                            <InputGroupAddon>menit</InputGroupAddon>
                        </InputGroup>
                    </div>
                    <div class="col-span-12 md:col-span-6 flex flex-col gap-2">
                        <label class="font-medium">Target Tugas Tambahan / bulan</label>
                        <InputGroup>
                            <InputNumber v-model="form.target_tambahan_jam" :min="0" :max="1000" showButtons fluid />
                            <InputGroupAddon>jam</InputGroupAddon>
                            <InputNumber v-model="form.target_tambahan_menit_sisa" :min="0" :max="59" showButtons fluid />
                            <InputGroupAddon>menit</InputGroupAddon>
                        </InputGroup>
                    </div>
                </div>

                <h5 class="m-0 mb-3 font-semibold">Bobot Komponen</h5>
                <div class="grid grid-cols-12 gap-4 mb-2">
                    <div class="col-span-12 md:col-span-4 flex flex-col gap-2">
                        <label class="font-medium">Kehadiran (%)</label>
                        <InputNumber v-model="form.bobot_kehadiran" :min="0" :max="100" suffix=" %" fluid />
                    </div>
                    <div class="col-span-12 md:col-span-4 flex flex-col gap-2">
                        <label class="font-medium">Tugas Utama (%)</label>
                        <InputNumber v-model="form.bobot_utama" :min="0" :max="100" suffix=" %" fluid />
                    </div>
                    <div class="col-span-12 md:col-span-4 flex flex-col gap-2">
                        <label class="font-medium">Tugas Tambahan (%)</label>
                        <InputNumber v-model="form.bobot_tambahan" :min="0" :max="100" suffix=" %" fluid />
                    </div>
                </div>
                <div class="mb-6">
                    <Tag :value="`Total: ${totalBobot}%`" :severity="bobotValid ? 'success' : 'danger'" />
                    <small v-if="!bobotValid" class="text-red-500 ml-2">Total bobot harus tepat 100%.</small>
                    <small v-if="form.errors.bobot_kehadiran" class="text-red-500 ml-2">{{ form.errors.bobot_kehadiran }}</small>
                </div>

                <h5 class="m-0 mb-3 font-semibold">Nominal Insentif Dasar</h5>
                <div class="flex flex-col gap-2 mb-6 max-w-sm">
                    <label class="font-medium">Nominal (Rp)</label>
                    <InputNumber v-model="form.nominal_dasar" mode="currency" currency="IDR" locale="id-ID" :min="0" fluid />
                    <small class="text-muted-color">Kosongkan bila belum ditetapkan.</small>
                </div>

                <Button label="Simpan Konfigurasi" icon="pi pi-check" :loading="form.processing" :disabled="!bobotValid" @click="simpan" />
            </div>
        </div>
    </div>
</template>
