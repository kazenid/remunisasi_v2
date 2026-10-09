<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    konteks: { type: String, required: true }, // katimker | kabid | admin
    tahun: { type: Number, required: true },
    bulan: { type: Number, required: true },
    ringkasan: { type: Object, default: null },
    kehadiran: { type: Object, default: null },
    target: { type: Object, default: null },
    baris: { type: Array, default: () => [] },
});

const sumberTarget = {
    global: 'Target global',
    individu_umum: 'Target individu',
    individu_periodik: 'Target individu (periode ini)',
};

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
    katimker: 'Rekap Kinerja Saya',
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

        <!-- Ringkasan Katimker -->
        <template v-if="konteks === 'katimker' && ringkasan">
            <div class="col-span-12 md:col-span-4">
                <div class="card mb-0">
                    <span class="block text-muted-color font-medium mb-4">Tugas Utama (disetujui)</span>
                    <div class="text-surface-900 dark:text-surface-0 font-medium text-xl">{{ fmt(ringkasan.utama_menit) }}</div>
                </div>
            </div>
            <div class="col-span-12 md:col-span-4">
                <div class="card mb-0">
                    <span class="block text-muted-color font-medium mb-4">Tugas Tambahan (disetujui)</span>
                    <div class="text-surface-900 dark:text-surface-0 font-medium text-xl">{{ fmt(ringkasan.tambahan_menit) }}</div>
                </div>
            </div>
            <div class="col-span-12 md:col-span-4">
                <div class="card mb-0">
                    <span class="block text-muted-color font-medium mb-4">Total</span>
                    <div class="text-primary font-medium text-xl">{{ fmt(ringkasan.total_menit) }}</div>
                </div>
            </div>

            <!-- Target efektif (persiapan) -->
            <div class="col-span-12" v-if="target">
                <div class="card">
                    <div class="flex items-center justify-between mb-4">
                        <h5 class="m-0 font-semibold text-lg">Target Jam</h5>
                        <Tag :value="sumberTarget[target.sumber] ?? target.sumber" severity="secondary" />
                    </div>
                    <div class="grid grid-cols-12 gap-4">
                        <div class="col-span-6 md:col-span-3">
                            <span class="block text-muted-color text-sm">Target Tugas Utama</span>
                            <span class="text-surface-900 dark:text-surface-0 font-medium text-lg">{{ target.utama_menit !== null ? fmt(target.utama_menit) : 'Belum diatur' }}</span>
                        </div>
                        <div class="col-span-6 md:col-span-3">
                            <span class="block text-muted-color text-sm">Target Tugas Tambahan</span>
                            <span class="text-surface-900 dark:text-surface-0 font-medium text-lg">{{ target.tambahan_menit !== null ? fmt(target.tambahan_menit) : 'Belum diatur' }}</span>
                        </div>
                        <div class="col-span-6 md:col-span-3">
                            <span class="block text-muted-color text-sm">Capaian Utama</span>
                            <span class="text-primary font-medium text-lg">{{ target.utama_menit ? Math.min(100, Math.round(ringkasan.utama_menit / target.utama_menit * 100)) + '%' : '—' }}</span>
                        </div>
                        <div class="col-span-6 md:col-span-3">
                            <span class="block text-muted-color text-sm">Capaian Tambahan</span>
                            <span class="text-primary font-medium text-lg">{{ target.tambahan_menit ? Math.min(100, Math.round(ringkasan.tambahan_menit / target.tambahan_menit * 100)) + '%' : '—' }}</span>
                        </div>
                    </div>
                    <small class="text-muted-color block mt-3">Capaian dibatasi 100%. Persiapan simulasi — belum dipakai menghitung insentif.</small>
                </div>
            </div>

            <!-- Kehadiran dari penilaian absensi SIKEP (read-only, persiapan simulasi) -->
            <div class="col-span-12">
                <div class="card">
                    <div class="flex items-center justify-between mb-4">
                        <h5 class="m-0 font-semibold text-lg">Kehadiran (penilaian absensi SIKEP)</h5>
                        <Tag value="Persiapan · belum dihitung ke insentif" severity="secondary" />
                    </div>
                    <div v-if="kehadiran?.tersedia">
                        <div class="flex items-end gap-6 mb-4">
                            <div>
                                <span class="block text-muted-color text-sm">Persen Kehadiran</span>
                                <span class="text-primary font-medium text-2xl">{{ kehadiran.persen_kehadiran }}%</span>
                            </div>
                            <div>
                                <span class="block text-muted-color text-sm">Total Pengurangan</span>
                                <span class="text-surface-900 dark:text-surface-0 font-medium text-xl">{{ kehadiran.total_pengurangan }}%</span>
                            </div>
                        </div>
                        <div class="grid grid-cols-12 gap-4">
                            <div class="col-span-6 md:col-span-2" v-for="a in [
                                { label: 'Hadir', nilai: kehadiran.hadir },
                                { label: 'Terlambat', nilai: kehadiran.terlambat },
                                { label: 'Pulang awal', nilai: kehadiran.pulang_awal },
                                { label: 'Tidak scan', nilai: kehadiran.tidak_scan },
                                { label: 'Tanpa ket.', nilai: kehadiran.tanpa_keterangan },
                                { label: 'Cuti', nilai: kehadiran.cuti },
                                { label: 'Libur', nilai: kehadiran.libur },
                                { label: 'Hari kerja', nilai: kehadiran.hari_kerja },
                            ]" :key="a.label">
                                <span class="block text-muted-color text-sm">{{ a.label }}</span>
                                <span class="text-surface-900 dark:text-surface-0 font-medium text-lg">{{ a.nilai }}</span>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-muted-color m-0">Data absensi untuk periode ini belum tersedia di SIKEP.</p>
                </div>
            </div>
        </template>

        <!-- Tabel Kabid/Admin -->
        <div v-else class="col-span-12">
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
                </DataTable>
            </div>
        </div>
    </div>
</template>
