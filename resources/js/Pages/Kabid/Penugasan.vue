<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { watch } from 'vue';

defineOptions({ layout: AppLayout });

defineProps({
    binaan: { type: Array, default: () => [] },
    kegiatan: { type: Array, default: () => [] },
});

const toast = useToast();
const page = usePage();

const form = useForm({
    katimker_sikep_user_id: null,
    tanggal: new Date(),
    kegiatan_id: null,
    uraian: '',
});

function transform(d) {
    return { ...d, tanggal: d.tanggal instanceof Date ? d.tanggal.toISOString().slice(0, 10) : d.tanggal };
}

function simpan() {
    form.transform(transform).post(route('kabid.penugasan.store'), {
        onSuccess: () => toast.add({ severity: 'success', summary: 'Penugasan dibuat', detail: 'Menunggu bukti dari Katimker.', life: 3000 }),
    });
}

watch(
    () => page.props.flash?.error,
    (msg) => { if (msg) toast.add({ severity: 'error', summary: 'Gagal', detail: msg, life: 5000 }); },
    { immediate: true },
);
</script>

<template>
    <Head title="Penugasan Tambahan" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12 xl:col-span-7">
            <div class="card">
                <h4 class="m-0 mb-2 font-semibold text-xl">Beri Penugasan Tambahan</h4>
                <span class="text-muted-color text-sm">Tugas otomatis berjenis tambahan. Katimker melengkapi bukti dan durasi, lalu mengajukan untuk dinilai.</span>

                <div class="flex flex-col gap-5 mt-6">
                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Katimker</label>
                        <Select
                            v-model="form.katimker_sikep_user_id"
                            :options="binaan" optionLabel="nama" optionValue="sikep_user_id"
                            placeholder="Pilih Katimker binaan" filter fluid
                            :class="{ 'p-invalid': form.errors.katimker_sikep_user_id }"
                        />
                        <small v-if="form.errors.katimker_sikep_user_id" class="text-red-500">{{ form.errors.katimker_sikep_user_id }}</small>
                        <small v-if="!binaan.length" class="text-orange-500">Belum ada Katimker yang dipetakan ke Anda.</small>
                    </div>

                    <div class="flex flex-col gap-2 md:w-1/2">
                        <label class="font-medium">Tanggal</label>
                        <DatePicker v-model="form.tanggal" dateFormat="dd/mm/yy" showIcon fluid />
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Kegiatan</label>
                        <Select
                            v-model="form.kegiatan_id"
                            :options="kegiatan" optionLabel="nama" optionValue="id"
                            placeholder="Pilih kegiatan" filter fluid
                            :class="{ 'p-invalid': form.errors.kegiatan_id }"
                        />
                        <small v-if="form.errors.kegiatan_id" class="text-red-500">{{ form.errors.kegiatan_id }}</small>
                        <small v-if="!kegiatan.length" class="text-orange-500">Master kegiatan masih kosong. Hubungi administrator.</small>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Uraian <span class="text-muted-color font-normal">(opsional)</span></label>
                        <Textarea v-model="form.uraian" rows="3" autoResize />
                    </div>

                    <div>
                        <Button label="Buat Penugasan" icon="pi pi-plus" :loading="form.processing" :disabled="!binaan.length" @click="simpan" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
