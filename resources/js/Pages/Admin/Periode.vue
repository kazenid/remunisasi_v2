<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { ref } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    periods: { type: Array, default: () => [] },
});

const toast = useToast();

const namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// Form buat periode baru.
const now = new Date();
const buatForm = useForm({ tahun: now.getFullYear(), bulan: now.getMonth() + 1 });
const bulanOptions = namaBulan.slice(1).map((n, i) => ({ label: n, value: i + 1 }));

function buat() {
    buatForm.post(route('admin.periode.store'), {
        preserveScroll: true,
        onSuccess: () => toast.add({ severity: 'success', summary: 'Berhasil', detail: 'Periode disiapkan.', life: 3000 }),
    });
}

// Edit periode (dialog).
const dialog = ref(false);
const editForm = useForm({ id: null, cut_off: null, target_cair: null, status: 'terbuka' });

function bukaEdit(p) {
    editForm.id = p.id;
    editForm.cut_off = p.cut_off ? new Date(p.cut_off) : null;
    editForm.target_cair = p.target_cair ? new Date(p.target_cair) : null;
    editForm.status = p.status;
    dialog.value = true;
}

function toDateStr(v) {
    return v instanceof Date ? v.toISOString().slice(0, 10) : v;
}

function simpan() {
    editForm
        .transform((d) => ({ ...d, cut_off: toDateStr(d.cut_off), target_cair: toDateStr(d.target_cair) }))
        .post(route('admin.periode.update', editForm.id), {
            preserveScroll: true,
            onSuccess: () => {
                dialog.value = false;
                toast.add({ severity: 'success', summary: 'Tersimpan', detail: 'Periode diperbarui.', life: 3000 });
            },
        });
}
</script>

<template>
    <Head title="Manajemen Periode" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12">
            <div class="card">
                <div class="flex flex-wrap items-end justify-between gap-4 mb-4">
                    <div>
                        <h4 class="m-0 font-semibold text-xl">Manajemen Periode</h4>
                        <span class="text-muted-color text-sm">Atur cut-off, target pencairan, dan status kunci tiap periode.</span>
                    </div>
                    <div class="flex items-end gap-2">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm text-muted-color">Bulan</label>
                            <Select v-model="buatForm.bulan" :options="bulanOptions" optionLabel="label" optionValue="value" class="w-40" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm text-muted-color">Tahun</label>
                            <InputNumber v-model="buatForm.tahun" :useGrouping="false" :min="2020" :max="2100" inputClass="w-28" />
                        </div>
                        <Button label="Siapkan Periode" icon="pi pi-plus" :loading="buatForm.processing" @click="buat" />
                    </div>
                </div>

                <DataTable :value="periods" dataKey="id" :paginator="periods.length > 12" :rows="12" responsiveLayout="scroll" stripedRows>
                    <template #empty>
                        <div class="text-center text-muted-color py-6">Belum ada periode. Siapkan periode di atas.</div>
                    </template>
                    <Column header="Periode" sortable style="min-width:12rem">
                        <template #body="{ data }">{{ namaBulan[data.bulan] }} {{ data.tahun }}</template>
                    </Column>
                    <Column field="cut_off" header="Cut-off" style="min-width:10rem">
                        <template #body="{ data }">{{ data.cut_off ?? '—' }}</template>
                    </Column>
                    <Column field="target_cair" header="Target Cair" style="min-width:10rem">
                        <template #body="{ data }">{{ data.target_cair ?? '—' }}</template>
                    </Column>
                    <Column header="Status" style="min-width:9rem">
                        <template #body="{ data }">
                            <Tag :value="data.status === 'terkunci' ? 'Terkunci' : 'Terbuka'" :severity="data.status === 'terkunci' ? 'danger' : 'success'" />
                        </template>
                    </Column>
                    <Column header="Aksi" style="min-width:8rem">
                        <template #body="{ data }">
                            <Button icon="pi pi-pencil" outlined rounded @click="bukaEdit(data)" />
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>

    <Dialog v-model:visible="dialog" header="Ubah Periode" :modal="true" :style="{ width: '420px' }">
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-2">
                <label class="font-medium">Tanggal Cut-off</label>
                <DatePicker v-model="editForm.cut_off" dateFormat="dd/mm/yy" showIcon fluid />
            </div>
            <div class="flex flex-col gap-2">
                <label class="font-medium">Target Pencairan</label>
                <DatePicker v-model="editForm.target_cair" dateFormat="dd/mm/yy" showIcon fluid />
            </div>
            <div class="flex flex-col gap-2">
                <label class="font-medium">Status</label>
                <Select v-model="editForm.status" :options="[{label:'Terbuka',value:'terbuka'},{label:'Terkunci',value:'terkunci'}]" optionLabel="label" optionValue="value" fluid />
            </div>
        </div>
        <template #footer>
            <Button label="Batal" icon="pi pi-times" text @click="dialog = false" />
            <Button label="Simpan" icon="pi pi-check" :loading="editForm.processing" @click="simpan" />
        </template>
    </Dialog>
</template>
