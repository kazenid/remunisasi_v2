<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { formatDurasi, formatRupiah, formatTanggal } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    laporan: { type: Object, required: true },
});

const persen = (n) => `${Number(n).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}%`;

function unduh() {
    // Unduhan berkas: navigasi langsung (bukan XHR Inertia).
    window.location.href = route('admin.insentif.hitung.unduh', { tahun: props.laporan.tahun, bulan: props.laporan.bulan });
}

function cetak() {
    window.print();
}

function kembali() {
    router.visit(route('admin.insentif.hitung.index', { tahun: props.laporan.tahun, bulan: props.laporan.bulan }));
}
</script>

<template>
    <Head :title="`Laporan Insentif ${laporan.label}`" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12">
            <div class="card">
                <div class="flex flex-wrap items-start justify-between gap-4 mb-6 print:hidden">
                    <div>
                        <h4 class="m-0 font-semibold text-xl">Laporan Insentif</h4>
                        <span class="text-muted-color text-sm">Ringkasan insentif seluruh Katimker pada satu periode.</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button label="Download Excel" icon="pi pi-file-excel" severity="success" @click="unduh" />
                        <Button label="Cetak" icon="pi pi-print" severity="secondary" outlined @click="cetak" />
                        <Button label="Kembali" icon="pi pi-arrow-left" severity="secondary" text @click="kembali" />
                    </div>
                </div>

                <div class="mb-6">
                    <h3 class="m-0 mb-2 font-semibold text-2xl">Laporan Insentif Pegawai · {{ laporan.label }}</h3>
                    <div class="flex flex-wrap items-center gap-3 text-sm text-muted-color">
                        <Tag :value="laporan.status" :severity="laporan.status === 'Final' ? 'success' : 'warn'" />
                        <span>Target realisasi: <b class="text-surface-900 dark:text-surface-0">{{ formatTanggal(laporan.target_realisasi) }}</b></span>
                        <span v-if="laporan.status !== 'Final'">Angka masih simulasi (belum difinalkan).</span>
                    </div>
                </div>

                <div class="grid grid-cols-12 gap-4 mb-6">
                    <div class="col-span-6 md:col-span-3">
                        <span class="block text-muted-color text-sm">Jumlah Pegawai</span>
                        <span class="font-semibold text-xl">{{ laporan.total.jumlah_pegawai }}</span>
                    </div>
                    <div class="col-span-6 md:col-span-3">
                        <span class="block text-muted-color text-sm">Rata-rata Pencapaian</span>
                        <span class="font-semibold text-xl">{{ persen(laporan.total.rata_persen_total) }}</span>
                    </div>
                    <div class="col-span-6 md:col-span-3">
                        <span class="block text-muted-color text-sm">Total Plafon</span>
                        <span class="font-semibold text-xl">{{ formatRupiah(laporan.total.nominal_plafon) }}</span>
                    </div>
                    <div class="col-span-6 md:col-span-3">
                        <span class="block text-muted-color text-sm">Total Insentif</span>
                        <span class="font-semibold text-xl text-primary">{{ formatRupiah(laporan.total.nominal_insentif) }}</span>
                    </div>
                </div>

                <DataTable :value="laporan.baris" dataKey="katimker_sikep_user_id" :paginator="laporan.baris.length > 25" :rows="25" responsiveLayout="scroll" stripedRows>
                    <template #empty>
                        <div class="text-center text-muted-color py-6">Tidak ada Katimker aktif untuk periode ini.</div>
                    </template>
                    <Column header="No" style="width:4rem">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>
                    <Column field="nama" header="Nama" sortable style="min-width:14rem" />
                    <Column header="Kehadiran" style="min-width:8rem">
                        <template #body="{ data }">
                            <span v-if="data.kehadiran_tersedia">{{ persen(data.persen_kehadiran) }}</span>
                            <span v-else class="text-muted-color">Belum tersedia</span>
                        </template>
                    </Column>
                    <Column header="Utama" style="min-width:11rem">
                        <template #body="{ data }">
                            {{ persen(data.persen_utama) }}
                            <small class="block text-muted-color">{{ formatDurasi(data.utama_menit) }} / {{ data.target_utama_menit !== null ? formatDurasi(data.target_utama_menit) : '—' }}</small>
                        </template>
                    </Column>
                    <Column header="Tambahan" style="min-width:11rem">
                        <template #body="{ data }">
                            {{ persen(data.persen_tambahan) }}
                            <small class="block text-muted-color">{{ formatDurasi(data.tambahan_menit) }} / {{ data.target_tambahan_menit !== null ? formatDurasi(data.target_tambahan_menit) : '—' }}</small>
                        </template>
                    </Column>
                    <Column header="Total" style="min-width:7rem">
                        <template #body="{ data }"><b>{{ persen(data.persen_total) }}</b></template>
                    </Column>
                    <Column header="Plafon" style="min-width:9rem">
                        <template #body="{ data }">{{ formatRupiah(data.nominal_plafon) }}</template>
                    </Column>
                    <Column header="Insentif" style="min-width:9rem">
                        <template #body="{ data }"><b>{{ formatRupiah(data.nominal_insentif) }}</b></template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>
</template>
