<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatDurasi, formatRupiah, formatTanggal, jenisLabel, namaBulan, persen, statusMeta } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tahun: { type: Number, required: true },
    bulan: { type: Number, required: true },
    periode: { type: Object, required: true },
    ringkasan: { type: Object, required: true },
    kehadiran: { type: Object, default: () => ({ tersedia: false }) },
    target: { type: Object, default: null },
    insentif: { type: Object, required: true },
    ringkas_status: { type: Object, required: true },
    tugas: { type: Array, default: () => [] },
});

// ─── Pemilih periode ───
const bulanOptions = namaBulan.slice(1).map((n, i) => ({ label: n, value: i + 1 }));
const tahunOptions = computed(() => {
    const t = new Date().getFullYear();
    return [t - 2, t - 1, t, t + 1].map((y) => ({ label: String(y), value: y }));
});
const pilihBulan = ref(props.bulan);
const pilihTahun = ref(props.tahun);

function muat(tahun = pilihTahun.value, bulan = pilihBulan.value) {
    router.get(route('katimker.rekap.index'), { tahun, bulan }, { preserveScroll: true });
}

function geser(delta) {
    const d = new Date(props.tahun, props.bulan - 1 + delta, 1);
    muat(d.getFullYear(), d.getMonth() + 1);
}

// ─── Insentif ───
const final = computed(() => props.insentif.status === 'final');
const waktuFinal = computed(() => props.insentif.difinalkan_pada
    ? new Date(props.insentif.difinalkan_pada).toLocaleString('id-ID', { dateStyle: 'long', timeStyle: 'short' })
    : null);
const belumTerperoleh = computed(() => Math.max(0, props.insentif.plafon - props.insentif.nominal));
const pctPlafon = computed(() => Math.min(100, Math.round(props.insentif.persen_total)));

const urlSlip = computed(() => route('katimker.rekap.slip', { tahun: props.tahun, bulan: props.bulan }));
function unduhSlip() {
    // Unduhan berkas: navigasi langsung (bukan XHR Inertia).
    window.location.href = urlSlip.value;
}

function detailCapaian(k) {
    if (k.kode === 'kehadiran') return 'Persentase kehadiran';
    if (k.target_menit) return `${formatDurasi(k.menit)} dari ${formatDurasi(k.target_menit)}`;
    return `${formatDurasi(k.menit)} · target belum diatur`;
}

const fmtPersen = (n) => `${Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 })}%`;

// ─── Ringkasan tugas ───
const kartuStatus = computed(() => {
    const r = props.ringkas_status;
    const jml = (...k) => k.reduce((n, x) => n + (r[x]?.jumlah ?? 0), 0);
    const mnt = (...k) => k.reduce((n, x) => n + (r[x]?.menit ?? 0), 0);

    return [
        { label: 'Disetujui', icon: 'pi pi-check-circle', color: 'green', jumlah: jml('disetujui'), menit: mnt('disetujui'), hint: 'jam disetujui' },
        { label: 'Proses Penilaian', icon: 'pi pi-send', color: 'blue', jumlah: jml('diajukan'), menit: mnt('diajukan'), hint: 'jam diajukan' },
        { label: 'Revisi / Menunggu Bukti', icon: 'pi pi-exclamation-triangle', color: 'orange', jumlah: jml('revisi', 'menunggu_bukti'), menit: mnt('revisi', 'menunggu_bukti'), hint: 'perlu tindak lanjut' },
        { label: 'Ditolak', icon: 'pi pi-times-circle', color: 'red', jumlah: jml('ditolak'), menit: mnt('ditolak'), hint: 'final, tidak dihitung' },
    ];
});

const pctUtama = computed(() => persen(props.ringkasan.utama_menit, props.target?.utama_menit));
const pctTambahan = computed(() => persen(props.ringkasan.tambahan_menit, props.target?.tambahan_menit));

const sumberTarget = {
    global: 'Target global',
    individu_umum: 'Target individu',
    individu_periodik: 'Target individu (periode ini)',
};

const absensi = computed(() => [
    { label: 'Hadir', nilai: props.kehadiran.hadir },
    { label: 'Terlambat', nilai: props.kehadiran.terlambat },
    { label: 'Pulang awal', nilai: props.kehadiran.pulang_awal },
    { label: 'Tidak scan', nilai: props.kehadiran.tidak_scan },
    { label: 'Tanpa ket.', nilai: props.kehadiran.tanpa_keterangan },
    { label: 'Cuti', nilai: props.kehadiran.cuti },
    { label: 'Libur', nilai: props.kehadiran.libur },
    { label: 'Hari kerja', nilai: props.kehadiran.hari_kerja },
]);

const filters = ref({ global: { value: null, matchMode: 'contains' } });
</script>

<template>
    <Head title="Rekap Kinerja Saya" />

    <div class="grid grid-cols-12 gap-6">
        <!-- ─── Header + pemilih periode ─── -->
        <div class="col-span-12">
            <div class="card mb-0">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h4 class="m-0 font-semibold text-2xl">Rekap Kinerja &amp; Insentif Saya</h4>
                        <div class="flex flex-wrap items-center gap-2 mt-2 text-sm text-muted-color">
                            <span><i class="pi pi-calendar mr-1"></i>Periode {{ namaBulan[bulan] }} {{ tahun }}</span>
                            <Tag
                                :value="periode.terkunci ? 'Input & penilaian ditutup' : 'Input & penilaian terbuka'"
                                :severity="periode.terkunci ? 'secondary' : 'success'"
                                :icon="periode.terkunci ? 'pi pi-lock' : 'pi pi-lock-open'"
                            />
                            <span>· Cut-off {{ formatTanggal(periode.cut_off) }} · Target realisasi {{ formatTanggal(periode.target_realisasi) }}</span>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button icon="pi pi-chevron-left" severity="secondary" outlined aria-label="Bulan sebelumnya" @click="geser(-1)" />
                        <Select v-model="pilihBulan" :options="bulanOptions" optionLabel="label" optionValue="value" class="w-40" @change="muat()" />
                        <Select v-model="pilihTahun" :options="tahunOptions" optionLabel="label" optionValue="value" class="w-28" @change="muat()" />
                        <Button icon="pi pi-chevron-right" severity="secondary" outlined aria-label="Bulan berikutnya" @click="geser(1)" />
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── Perolehan insentif ─── -->
        <div class="col-span-12 xl:col-span-5">
            <section class="card mb-0 h-full flex flex-col">
                <div class="flex items-start justify-between gap-3 mb-4">
                    <div>
                        <h5 class="m-0 font-semibold text-lg">{{ final ? 'Insentif Ditetapkan' : 'Perkiraan Perolehan' }}</h5>
                        <p class="m-0 mt-1 text-sm text-muted-color">{{ final ? 'Sudah difinalkan Admin' : 'Belum final, dapat berubah' }}</p>
                    </div>
                    <Tag
                        :value="final ? 'FINAL' : 'ESTIMASI'"
                        :severity="final ? 'success' : 'warn'"
                        :icon="final ? 'pi pi-check-circle' : 'pi pi-clock'"
                    />
                </div>

                <div class="text-4xl font-bold text-surface-900 dark:text-surface-0 leading-tight">{{ formatRupiah(insentif.nominal) }}</div>
                <div class="text-sm text-muted-color mt-1">
                    {{ fmtPersen(insentif.persen_total) }} dari plafon {{ formatRupiah(insentif.plafon) }}
                </div>

                <ProgressBar
                    :value="pctPlafon"
                    :showValue="false"
                    class="mt-4"
                    style="height:0.6rem"
                    :pt="{ value: { style: { background: final ? 'var(--p-green-500)' : 'var(--p-orange-500)' } } }"
                />
                <div class="flex justify-between text-xs text-muted-color mt-2">
                    <span>Diperoleh {{ formatRupiah(insentif.nominal) }}</span>
                    <span>Belum terperoleh {{ formatRupiah(belumTerperoleh) }}</span>
                </div>

                <p v-if="final && waktuFinal" class="text-sm text-muted-color mt-4 mb-0">
                    <i class="pi pi-verified mr-1 text-green-500"></i>Difinalkan {{ waktuFinal }}
                </p>

                <div class="mt-auto pt-6 flex flex-wrap gap-2">
                    <Button label="Download Slip (PDF)" icon="pi pi-download" @click="unduhSlip" />
                </div>
                <small class="text-muted-color mt-2">
                    Slip berstatus <b>{{ final ? 'Final' : 'Estimasi' }}</b> mengikuti kondisi saat ini.
                </small>
            </section>
        </div>

        <!-- ─── Rincian nominal ─── -->
        <div class="col-span-12 xl:col-span-7">
            <section class="card mb-0 h-full">
                <h5 class="m-0 mb-1 font-semibold text-lg">Rincian Perolehan</h5>
                <p class="m-0 mb-4 text-sm text-muted-color">Diperoleh = plafon × bobot × pencapaian komponen.</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="text-left text-muted-color border-b border-surface-200 dark:border-surface-700">
                                <th class="py-2 pr-3 font-medium">Komponen</th>
                                <th class="py-2 pr-3 font-medium text-right">Bobot</th>
                                <th class="py-2 pr-3 font-medium">Capaian</th>
                                <th class="py-2 pr-3 font-medium text-right">Pencapaian</th>
                                <th class="py-2 pr-3 font-medium text-right">Maksimal</th>
                                <th class="py-2 font-medium text-right">Diperoleh</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="k in insentif.komponen" :key="k.kode" class="border-b border-surface-100 dark:border-surface-800 align-top">
                                <td class="py-3 pr-3 font-medium text-surface-900 dark:text-surface-0">{{ k.label }}</td>
                                <td class="py-3 pr-3 text-right">{{ fmtPersen(k.bobot) }}</td>
                                <td class="py-3 pr-3 text-muted-color">{{ detailCapaian(k) }}</td>
                                <td class="py-3 pr-3 text-right">{{ fmtPersen(k.persen) }}</td>
                                <td class="py-3 pr-3 text-right text-muted-color">{{ formatRupiah(k.maksimal) }}</td>
                                <td class="py-3 text-right font-semibold text-surface-900 dark:text-surface-0">{{ formatRupiah(k.diperoleh) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold">
                                <td class="pt-3 pr-3" colspan="3">Total</td>
                                <td class="pt-3 pr-3 text-right">{{ fmtPersen(insentif.persen_total) }}</td>
                                <td class="pt-3 pr-3 text-right">{{ formatRupiah(insentif.plafon) }}</td>
                                <td class="pt-3 text-right text-primary">{{ formatRupiah(insentif.nominal) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div v-if="insentif.catatan.length" class="mt-4 flex flex-col gap-2">
                    <Message v-for="c in insentif.catatan" :key="c" :severity="c.startsWith('Angka ini') ? 'info' : 'warn'" :closable="false" size="small">{{ c }}</Message>
                </div>
            </section>
        </div>

        <!-- ─── Ringkasan status tugas ─── -->
        <div v-for="k in kartuStatus" :key="k.label" class="col-span-12 sm:col-span-6 xl:col-span-3">
            <KpiCard :label="k.label" :value="`${k.jumlah} tugas`" :icon="k.icon" :color="k.jumlah ? k.color : 'surface'"
                :hint="`${formatDurasi(k.menit)} · ${k.hint}`" />
        </div>

        <!-- ─── Capaian target ─── -->
        <div class="col-span-12 xl:col-span-6">
            <WidgetCard title="Capaian Target Jam" subtitle="Jam disetujui dibanding target periode ini">
                <template v-if="target">
                    <div class="flex flex-col gap-6">
                        <ProgressRow label="Tugas Utama" color="primary" :percent="pctUtama"
                            :detail="target.utama_menit !== null ? `${formatDurasi(ringkasan.utama_menit)} dari ${formatDurasi(target.utama_menit)}` : 'Target belum diatur'" />
                        <ProgressRow label="Tugas Tambahan" color="cyan" :percent="pctTambahan"
                            :detail="target.tambahan_menit !== null ? `${formatDurasi(ringkasan.tambahan_menit)} dari ${formatDurasi(target.tambahan_menit)}` : 'Target belum diatur'" />
                    </div>
                    <div class="flex items-center justify-between mt-6 pt-4 border-t border-surface text-sm">
                        <span class="text-muted-color">Total jam disetujui</span>
                        <span class="font-semibold">{{ formatDurasi(ringkasan.total_menit) }}</span>
                    </div>
                    <Tag class="mt-3" :value="sumberTarget[target.sumber] ?? target.sumber" severity="secondary" />
                </template>
            </WidgetCard>
        </div>

        <!-- ─── Kehadiran ─── -->
        <div class="col-span-12 xl:col-span-6">
            <WidgetCard title="Kehadiran" subtitle="Penilaian absensi dari SIKEP">
                <template v-if="kehadiran?.tersedia">
                    <div class="flex items-end gap-8 mb-5">
                        <div>
                            <span class="block text-muted-color text-sm">Persen Kehadiran</span>
                            <span class="text-primary font-semibold text-3xl">{{ kehadiran.persen_kehadiran }}%</span>
                        </div>
                        <div>
                            <span class="block text-muted-color text-sm">Total Pengurangan</span>
                            <span class="font-semibold text-xl">{{ kehadiran.total_pengurangan }}%</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-12 gap-4">
                        <div v-for="a in absensi" :key="a.label" class="col-span-6 md:col-span-3">
                            <span class="block text-muted-color text-sm">{{ a.label }}</span>
                            <span class="font-medium text-lg">{{ a.nilai }}</span>
                        </div>
                    </div>
                </template>
                <p v-else class="text-muted-color m-0">Data absensi untuk periode ini belum tersedia di SIKEP.</p>
            </WidgetCard>
        </div>

        <!-- ─── Daftar tugas periode ─── -->
        <div class="col-span-12">
            <div class="card mb-0">
                <DataTable :value="tugas" dataKey="id" :filters="filters" :globalFilterFields="['judul']" :paginator="tugas.length > 10" :rows="10" responsiveLayout="scroll" stripedRows>
                    <template #header>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h5 class="m-0 font-semibold text-lg">Tugas Periode Ini</h5>
                                <span class="text-sm text-muted-color">Hanya tugas berstatus disetujui yang dihitung ke insentif.</span>
                            </div>
                            <IconField>
                                <InputIcon><i class="pi pi-search" /></InputIcon>
                                <InputText v-model="filters.global.value" placeholder="Cari kegiatan..." />
                            </IconField>
                        </div>
                    </template>
                    <template #empty>
                        <div class="text-center text-muted-color py-6">Belum ada tugas pada periode ini.</div>
                    </template>
                    <Column field="tanggal" header="Tanggal" sortable style="min-width:9rem">
                        <template #body="{ data }">{{ formatTanggal(data.tanggal) }}</template>
                    </Column>
                    <Column field="judul" header="Kegiatan" sortable style="min-width:16rem" />
                    <Column field="jenis" header="Jenis" sortable style="min-width:8rem">
                        <template #body="{ data }">{{ jenisLabel[data.jenis] }}</template>
                    </Column>
                    <Column header="Diajukan" style="min-width:9rem">
                        <template #body="{ data }">{{ formatDurasi(data.durasi_diajukan_menit) }}</template>
                    </Column>
                    <Column header="Disetujui" style="min-width:9rem">
                        <template #body="{ data }">
                            <span v-if="data.durasi_disetujui_menit !== null">{{ formatDurasi(data.durasi_disetujui_menit) }}</span>
                            <span v-else class="text-muted-color">—</span>
                        </template>
                    </Column>
                    <Column field="status" header="Status" sortable style="min-width:11rem">
                        <template #body="{ data }">
                            <Tag :value="statusMeta[data.status]?.label ?? data.status" :severity="statusMeta[data.status]?.severity" />
                        </template>
                    </Column>
                    <Column :exportable="false" header="" style="min-width:7rem">
                        <template #body="{ data }">
                            <Link :href="route('katimker.tugas.show', data.id)"><Button label="Detail" icon="pi pi-eye" size="small" severity="secondary" outlined /></Link>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>
</template>
