<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    konteks: { type: String, required: true }, // kabid | admin
    tahun: { type: Number, required: true },
    bulan: { type: Number, required: true },
    baris: { type: Array, default: () => [] },
});

const namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

const bulanOptions = namaBulan.map((n, i) => ({ label: n, value: i + 1 }));
const tahunOptions = computed(() => {
    const t = new Date().getFullYear();
    return [t - 1, t, t + 1].map((y) => ({ label: String(y), value: y }));
});

const pilihTahun = ref(props.tahun);
const pilihBulan = ref(props.bulan);

const routeName = computed(() => `${props.konteks}.rekap.index`);

function muat() {
    router.get(route(routeName.value), { tahun: pilihTahun.value, bulan: pilihBulan.value }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function fmt(menit) {
    const j = Math.floor(menit / 60);
    const m = menit % 60;
    return [j ? `${j} jam` : null, m ? `${m} menit` : null].filter(Boolean).join(' ') || '0 menit';
}

const judul = computed(() => ({
    kabid: 'Rekap Kinerja Binaan',
    admin: 'Rekap Kinerja (Semua Katimker)',
}[props.konteks]));
</script>

<template>
    <Head title="Rekap Kinerja" />

    <div class="grid grid-cols-12 gap-8">
        <!-- Pemilih periode -->
        <div class="col-span-12">
            <div class="card">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h4 class="m-0 font-semibold text-xl">{{ judul }}</h4>
                        <span class="text-muted-color text-sm">Jam yang telah disetujui — label simulasi, bukan dasar pembayaran final.</span>
                    </div>
                    <div class="flex items-end gap-3">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm text-muted-color">Bulan</label>
                            <Select v-model="pilihBulan" :options="bulanOptions" optionLabel="label" optionValue="value" class="w-40" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm text-muted-color">Tahun</label>
                            <Select v-model="pilihTahun" :options="tahunOptions" optionLabel="label" optionValue="value" class="w-32" />
                        </div>
                        <Button label="Tampilkan" icon="pi pi-refresh" @click="muat" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabel Kabid/Admin -->
        <div class="col-span-12">
            <div class="card">
                <DataTable :value="baris" dataKey="katimker_sikep_user_id" :paginator="baris.length > 10" :rows="10" responsiveLayout="scroll" stripedRows>
                    <template #empty>
                        <div class="text-center text-muted-color py-6">Belum ada data rekap untuk periode ini.</div>
                    </template>
                    <Column field="nama" header="Katimker" sortable style="min-width:16rem" />
                    <Column header="Tugas Utama" sortable style="min-width:10rem">
                        <template #body="{ data }">{{ fmt(data.utama_menit) }}</template>
                    </Column>
                    <Column header="Tugas Tambahan" style="min-width:10rem">
                        <template #body="{ data }">{{ fmt(data.tambahan_menit) }}</template>
                    </Column>
                    <Column header="Total" style="min-width:10rem">
                        <template #body="{ data }"><span class="font-medium text-primary">{{ fmt(data.total_menit) }}</span></template>
                    </Column>
                    <Column v-if="konteks === 'kabid'" :exportable="false" header="Aksi" style="min-width:8rem">
                        <template #body="{ data }">
                            <Button
                                label="Detail" icon="pi pi-list" size="small" severity="secondary" outlined
                                @click="router.visit(route('kabid.rekap.tugas', data.katimker_sikep_user_id))"
                            />
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>
</template>
