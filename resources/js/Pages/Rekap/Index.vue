<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    rekaps: { type: Object, default: () => ({ data: [] }) },
    currentPeriode: { type: Object, default: () => ({}) },
    allPeriodes: { type: Array, default: () => [] },
    allUnits: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({ totalNominal: 0, totalPegawai: 0, avgPersen: 0 }) },
    filters: { type: Object, default: () => ({}) },
});

const filterPeriode = ref(props.filters.periode_id || props.currentPeriode?.id || '');
const filterUnit = ref(props.filters.unit || '');
const search = ref(props.filters.search || '');
const calculating = ref(false);

const formatCurrency = (val) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(val || 0);

const applyFilter = () => {
    router.get(route('rekap.index'), {
        periode_id: filterPeriode.value,
        unit: filterUnit.value,
        search: search.value,
    }, { preserveState: true });
};

const triggerRecalculate = () => {
    if (!props.currentPeriode?.id) return;
    if (confirm(`Hitung ulang seluruh rekap insentif untuk periode ${props.currentPeriode.nama}?`)) {
        calculating.value = true;
        router.post(route('rekap.calculate', props.currentPeriode.id), {}, {
            onFinish: () => {
                calculating.value = false;
            },
        });
    }
};

const printReport = () => {
    window.print();
};
</script>

<template>
    <Head title="Rekapitulasi Insentif Pegawai" />

    <div class="grid grid-cols-12 gap-6">
        <!-- Header & Action -->
        <div class="col-span-12">
            <div class="card mb-0">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold text-surface-900 dark:text-surface-0">Rekapitulasi Insentif Remunerasi</h2>
                        <p class="text-xs text-muted-color mt-1">
                            Laporan penghitungan insentif berbasis presensi SIKEP (40%), tugas utama (50%), dan tugas tambahan (10%).
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button 
                            label="Hitung Ulang Insentif" 
                            icon="pi pi-refresh" 
                            severity="primary" 
                            :loading="calculating" 
                            @click="triggerRecalculate" 
                        />
                        <Button 
                            label="Cetak" 
                            icon="pi pi-print" 
                            severity="secondary" 
                            outlined 
                            @click="printReport" 
                        />
                    </div>
                </div>

                <!-- Stats summary -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6 pt-4 border-t border-surface-200 dark:border-surface-700">
                    <div class="p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800">
                        <div class="text-xs text-emerald-600 dark:text-emerald-300 font-medium">Total Anggaran Insentif</div>
                        <div class="text-xl font-bold text-emerald-900 dark:text-emerald-100 mt-1">
                            {{ formatCurrency(stats.totalNominal) }}
                        </div>
                    </div>
                    <div class="p-3 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
                        <div class="text-xs text-blue-600 dark:text-blue-300 font-medium">Jumlah Pegawai Terhitung</div>
                        <div class="text-xl font-bold text-blue-900 dark:text-blue-100 mt-1">
                            {{ stats.totalPegawai }} Pegawai
                        </div>
                    </div>
                    <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
                        <div class="text-xs text-amber-600 dark:text-amber-300 font-medium">Rata-Rata Capaian Kinerja</div>
                        <div class="text-xl font-bold text-amber-900 dark:text-amber-100 mt-1">
                            {{ stats.avgPersen }}%
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="col-span-12">
            <div class="card p-4">
                <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-semibold mb-1 text-muted-color">Periode Kinerja</label>
                        <select v-model="filterPeriode" @change="applyFilter" class="w-full text-xs p-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800">
                            <option v-for="p in allPeriodes" :key="p.id" :value="p.id">
                                {{ p.nama }} ({{ p.status }})
                            </option>
                        </select>
                    </div>
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-semibold mb-1 text-muted-color">Unit Kerja</label>
                        <select v-model="filterUnit" @change="applyFilter" class="w-full text-xs p-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800">
                            <option value="">Semua Unit</option>
                            <option v-for="unit in allUnits" :key="unit" :value="unit">{{ unit }}</option>
                        </select>
                    </div>
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-semibold mb-1 text-muted-color">Pencarian Pegawai</label>
                        <input 
                            type="text" 
                            v-model="search" 
                            @keyup.enter="applyFilter" 
                            placeholder="Cari NIP atau Nama..." 
                            class="w-full text-xs p-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800" 
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Table Rekap -->
        <div class="col-span-12">
            <div class="card p-0 overflow-hidden">
                <DataTable :value="rekaps.data" responsiveLayout="scroll" :paginator="false" class="p-datatable-sm">
                    <template #empty>
                        <div class="text-center py-12 text-muted-color">
                            <i class="pi pi-chart-bar text-4xl mb-3 block"></i>
                            Belum ada data rekap insentif untuk periode ini. Klik "Hitung Ulang Insentif" untuk memulai komputasi.
                        </div>
                    </template>

                    <Column header="Pegawai" style="min-width: 220px">
                        <template #body="{ data }">
                            <div class="font-bold text-xs text-surface-900 dark:text-surface-0">{{ data.user?.name || '-' }}</div>
                            <div class="text-[11px] text-muted-color">NIP: {{ data.user?.nip || '-' }}</div>
                            <div class="text-[10px] text-primary">{{ data.user?.nama_unit || '-' }}</div>
                        </template>
                    </Column>

                    <Column header="Presensi (40%)" style="width: 130px">
                        <template #body="{ data }">
                            <div class="text-xs font-semibold">{{ data.persentase_kehadiran }}%</div>
                            <div class="text-[11px] text-teal-600 font-medium">Skor: {{ data.skor_kehadiran }}%</div>
                        </template>
                    </Column>

                    <Column header="Tugas Utama (50%)" style="width: 140px">
                        <template #body="{ data }">
                            <div class="text-xs font-semibold">{{ data.jam_utama_disetujui }} / {{ data.target_jam_utama }} Jam</div>
                            <div class="text-[11px] text-blue-600 font-medium">Skor: {{ data.skor_utama }}%</div>
                        </template>
                    </Column>

                    <Column header="Tugas Tambahan (10%)" style="width: 140px">
                        <template #body="{ data }">
                            <div class="text-xs font-semibold">{{ data.jam_tambahan_disetujui }} / {{ data.target_jam_tambahan }} Jam</div>
                            <div class="text-[11px] text-purple-600 font-medium">Skor: {{ data.skor_tambahan }}%</div>
                        </template>
                    </Column>

                    <Column header="Total Skor" style="width: 110px">
                        <template #body="{ data }">
                            <span class="inline-block px-2 py-1 rounded-md text-xs font-bold" :class="data.total_persen_kinerja >= 80 ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'">
                                {{ data.total_persen_kinerja }}%
                            </span>
                        </template>
                    </Column>

                    <Column header="Nominal Insentif" style="width: 160px; text-align: right">
                        <template #body="{ data }">
                            <div class="text-xs font-bold text-surface-900 dark:text-surface-0">
                                {{ formatCurrency(data.nominal_insentif) }}
                            </div>
                            <div class="text-[10px] text-muted-color">Plafon: {{ formatCurrency(data.nominal_dasar) }}</div>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>
</template>
