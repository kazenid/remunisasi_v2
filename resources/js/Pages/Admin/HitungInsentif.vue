<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { reactive, ref, watch } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tahun: { type: Number, required: true },
    bulan: { type: Number, required: true },
    baris: { type: Array, required: true },
    nominal_dasar: { type: Number, default: 0 },
    difinalkan: { type: Boolean, default: false },
});

const toast = useToast();
const confirm = useConfirm();
const page = usePage();

// Pilihan periode untuk "Hitung ulang".
const filter = reactive({ tahun: props.tahun, bulan: props.bulan });

const bulanOpsi = [
    { label: 'Januari', value: 1 },
    { label: 'Februari', value: 2 },
    { label: 'Maret', value: 3 },
    { label: 'April', value: 4 },
    { label: 'Mei', value: 5 },
    { label: 'Juni', value: 6 },
    { label: 'Juli', value: 7 },
    { label: 'Agustus', value: 8 },
    { label: 'September', value: 9 },
    { label: 'Oktober', value: 10 },
    { label: 'November', value: 11 },
    { label: 'Desember', value: 12 },
];

const rupiah = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

const persen = (n) => `${Number(n).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}%`;
const formatRupiah = (n) => rupiah.format(Number(n) || 0);

// State plafon per baris yang dapat diedit (prefill dari nominal_plafon).
const plafonEdit = ref({});
function syncPlafon() {
    const map = {};
    for (const r of props.baris) {
        map[r.katimker_sikep_user_id] = r.nominal_plafon;
    }
    plafonEdit.value = map;
}
syncPlafon();
watch(() => props.baris, syncPlafon);

const menyimpan = ref({});
const memfinalkan = ref(false);

function hitungUlang() {
    router.get(route('admin.insentif.hitung.index'), { tahun: filter.tahun, bulan: filter.bulan }, {
        preserveState: false,
    });
}

function simpanPlafon(row) {
    menyimpan.value[row.katimker_sikep_user_id] = true;
    router.post(route('admin.insentif.hitung.plafon'), {
        katimker_sikep_user_id: row.katimker_sikep_user_id,
        tahun: props.tahun,
        bulan: props.bulan,
        nominal: plafonEdit.value[row.katimker_sikep_user_id] ?? 0,
    }, {
        preserveScroll: true,
        onSuccess: () => toast.add({ severity: 'success', summary: 'Tersimpan', detail: `Plafon ${row.nama} disimpan.`, life: 3000 }),
        onFinish: () => { menyimpan.value[row.katimker_sikep_user_id] = false; },
    });
}

function konfirmasiFinalkan() {
    confirm.require({
        header: 'Finalkan Insentif',
        message: `Finalkan hasil insentif periode ${filter.bulan}/${filter.tahun} dan simpan ke database? Finalisasi ulang akan menimpa data periode ini.`,
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Finalkan',
        rejectLabel: 'Batal',
        accept: () => {
            memfinalkan.value = true;
            router.post(route('admin.insentif.hitung.finalkan'), {
                tahun: props.tahun,
                bulan: props.bulan,
            }, {
                preserveScroll: true,
                onSuccess: () => toast.add({ severity: 'success', summary: 'Difinalkan', detail: 'Hasil insentif tersimpan.', life: 3000 }),
                onFinish: () => { memfinalkan.value = false; },
            });
        },
    });
}

watch(
    () => page.props.flash?.error,
    (msg) => { if (msg) toast.add({ severity: 'error', summary: 'Gagal', detail: msg, life: 5000 }); },
    { immediate: true },
);
</script>

<template>
    <Head title="Hitung Insentif" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12">
            <div class="card">
                <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                    <div>
                        <h4 class="m-0 font-semibold text-xl">Hitung Insentif</h4>
                        <span class="text-muted-color">Simulasi perhitungan insentif per pegawai per periode.</span>
                    </div>
                    <Tag v-if="difinalkan" value="Periode telah difinalkan" severity="success" icon="pi pi-check-circle" />
                </div>

                <div class="flex flex-wrap items-end gap-4 mb-6">
                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Bulan</label>
                        <Select v-model="filter.bulan" :options="bulanOpsi" option-label="label" option-value="value" class="w-40" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="font-medium">Tahun</label>
                        <InputNumber v-model="filter.tahun" :min="2000" :max="2100" :use-grouping="false" class="w-32" />
                    </div>
                    <Button label="Hitung ulang" icon="pi pi-refresh" @click="hitungUlang" />
                    <Button label="Finalkan" icon="pi pi-check" severity="success" :loading="memfinalkan" @click="konfirmasiFinalkan" />
                </div>

                <DataTable :value="baris" data-key="katimker_sikep_user_id" paginator :rows="15" :rows-per-page-options="[15, 30, 50]" striped-rows>
                    <template #empty>
                        <span class="text-muted-color">Tidak ada Katimker aktif untuk periode ini.</span>
                    </template>

                    <Column field="nama" header="Nama" sortable style="min-width: 12rem" />

                    <Column header="Kehadiran %" style="min-width: 10rem">
                        <template #body="{ data }">
                            <span v-if="data.kehadiran_tersedia">{{ persen(data.persen_kehadiran) }}</span>
                            <Tag v-else value="Belum tersedia" severity="warn" />
                        </template>
                    </Column>

                    <Column header="Utama %" style="min-width: 12rem">
                        <template #body="{ data }">
                            <div class="flex flex-col gap-1">
                                <span>{{ persen(data.persen_utama) }}</span>
                                <small class="text-muted-color">{{ data.utama_menit }} / {{ data.target_utama_menit ?? '-' }} menit</small>
                                <Tag v-if="data.target_utama_belum_diset" value="Target belum diset" severity="warn" />
                            </div>
                        </template>
                    </Column>

                    <Column header="Tambahan %" style="min-width: 12rem">
                        <template #body="{ data }">
                            <div class="flex flex-col gap-1">
                                <span>{{ persen(data.persen_tambahan) }}</span>
                                <small class="text-muted-color">{{ data.tambahan_menit }} / {{ data.target_tambahan_menit ?? '-' }} menit</small>
                                <Tag v-if="data.target_tambahan_belum_diset" value="Target belum diset" severity="warn" />
                            </div>
                        </template>
                    </Column>

                    <Column header="Total %" style="min-width: 8rem">
                        <template #body="{ data }">
                            <span class="font-medium">{{ persen(data.persen_total) }}</span>
                        </template>
                    </Column>

                    <Column header="Plafon" style="min-width: 16rem">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <InputNumber
                                    v-model="plafonEdit[data.katimker_sikep_user_id]"
                                    mode="currency"
                                    currency="IDR"
                                    locale="id-ID"
                                    :min="0"
                                    :max-fraction-digits="0"
                                    class="w-40"
                                />
                                <Button
                                    icon="pi pi-save"
                                    severity="secondary"
                                    outlined
                                    :loading="!!menyimpan[data.katimker_sikep_user_id]"
                                    @click="simpanPlafon(data)"
                                />
                            </div>
                        </template>
                    </Column>

                    <Column header="Insentif" style="min-width: 10rem">
                        <template #body="{ data }">
                            <span class="font-medium text-surface-900 dark:text-surface-0">{{ formatRupiah(data.nominal_insentif) }}</span>
                        </template>
                    </Column>
                </DataTable>

                <small class="text-muted-color block mt-4">
                    Plafon default dari nominal dasar global ({{ formatRupiah(nominal_dasar) }}); dapat disesuaikan per pegawai.
                    Hasil hanya tersimpan ke database saat ditekan "Finalkan".
                </small>
            </div>
        </div>
    </div>
</template>
