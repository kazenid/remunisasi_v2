<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { ref, reactive, computed } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    katimker: { type: Array, default: () => [] },
    kabidOptions: { type: Array, default: () => [] },
});

const toast = useToast();

// Pilihan Kabid per baris (model lokal), diinit dari data server.
const pilihan = reactive({});
props.katimker.forEach((k) => {
    pilihan[k.sikep_user_id] = k.kabid_sikep_user_id;
});

const savingId = ref(null);

const filters = ref({ global: { value: null, matchMode: 'contains' } });

const belumDipetakan = computed(
    () => props.katimker.filter((k) => k.kabid_sikep_user_id === null).length,
);

function simpan(row) {
    const kabidId = pilihan[row.sikep_user_id];
    if (!kabidId) {
        toast.add({ severity: 'warn', summary: 'Belum lengkap', detail: 'Pilih Kabid terlebih dahulu.', life: 3000 });
        return;
    }

    savingId.value = row.sikep_user_id;
    router.post(
        route('admin.pemetaan.store'),
        { katimker_sikep_user_id: row.sikep_user_id, kabid_sikep_user_id: kabidId },
        {
            preserveScroll: true,
            onSuccess: () => toast.add({ severity: 'success', summary: 'Tersimpan', detail: `Pemetaan untuk ${row.nama} diperbarui.`, life: 3000 }),
            onError: (e) => toast.add({ severity: 'error', summary: 'Gagal', detail: Object.values(e)[0] ?? 'Terjadi kesalahan.', life: 4000 }),
            onFinish: () => (savingId.value = null),
        },
    );
}
</script>

<template>
    <Head title="Pemetaan Approver" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12">
            <div class="card">
                <DataTable
                    :value="katimker"
                    dataKey="sikep_user_id"
                    :paginator="true"
                    :rows="10"
                    :filters="filters"
                    :globalFilterFields="['nama', 'username', 'kabid_nama']"
                    responsiveLayout="scroll"
                    stripedRows
                >
                    <template #header>
                        <div class="flex flex-wrap justify-between items-center gap-4">
                            <div>
                                <h4 class="m-0 font-semibold text-xl">Pemetaan Approver</h4>
                                <span class="text-muted-color text-sm">Tetapkan Kabid penilai untuk tiap Katimker (satu Katimker satu Kabid).</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <Tag v-if="belumDipetakan > 0" :value="`${belumDipetakan} belum dipetakan`" severity="warn" />
                                <IconField>
                                    <InputIcon><i class="pi pi-search" /></InputIcon>
                                    <InputText v-model="filters['global'].value" placeholder="Cari Katimker..." />
                                </IconField>
                            </div>
                        </div>
                    </template>

                    <template #empty>
                        <div class="text-center text-muted-color py-6">Tidak ada Katimker ditemukan.</div>
                    </template>

                    <Column field="nama" header="Katimker" sortable style="min-width:18rem">
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <span class="font-medium text-surface-900 dark:text-surface-0">{{ data.nama }}</span>
                                <span class="text-muted-color text-sm">{{ data.username }}<template v-if="data.no_absen"> · {{ data.no_absen }}</template></span>
                            </div>
                        </template>
                    </Column>

                    <Column header="Status" style="min-width:10rem">
                        <template #body="{ data }">
                            <div class="flex flex-wrap gap-1">
                                <Tag
                                    :value="data.kabid_sikep_user_id ? 'Terpetakan' : 'Belum dipetakan'"
                                    :severity="data.kabid_sikep_user_id ? 'success' : 'warn'"
                                />
                                <Tag
                                    v-if="data.kabid_sikep_user_id && data.kabid_valid === false"
                                    value="bukan Kabid aktif"
                                    severity="warn"
                                />
                            </div>
                        </template>
                    </Column>

                    <Column header="Kabid Penilai" style="min-width:22rem">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <Select
                                    v-model="pilihan[data.sikep_user_id]"
                                    :options="kabidOptions"
                                    optionLabel="nama"
                                    optionValue="sikep_user_id"
                                    placeholder="Pilih Kabid"
                                    filter
                                    class="w-full"
                                    showClear
                                />
                                <Button
                                    icon="pi pi-check"
                                    label="Simpan"
                                    size="small"
                                    :loading="savingId === data.sikep_user_id"
                                    :disabled="!pilihan[data.sikep_user_id] || pilihan[data.sikep_user_id] === data.kabid_sikep_user_id"
                                    @click="simpan(data)"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>
</template>
