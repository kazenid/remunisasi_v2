<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    formatDurasi, formatRupiah, formatTanggal, hariKe, jenisLabel, namaBulan, persen, statusMeta, waktuRelatif,
} from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    roles: { type: Object, default: () => ({}) },
    periode: { type: Object, default: () => ({}) },
    katimker: { type: Object, default: null },
    kabid: { type: Object, default: null },
    admin: { type: Object, default: null },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);

const labelPeriode = computed(() => `${namaBulan[props.periode.bulan] ?? ''} ${props.periode.tahun ?? ''}`);

const sapaan = computed(() => {
    const jam = new Date().getHours();
    if (jam < 11) return 'Selamat pagi';
    if (jam < 15) return 'Selamat siang';
    if (jam < 18) return 'Selamat sore';
    return 'Selamat malam';
});

// ─── Pemilih tampilan (hanya muncul bila user memegang lebih dari satu peran) ───
const tampilan = computed(() => {
    const t = [];
    if (props.katimker) t.push({ label: 'Katimker', value: 'katimker', icon: 'pi pi-list-check' });
    if (props.kabid) t.push({ label: 'Kabid', value: 'kabid', icon: 'pi pi-check-square' });
    if (props.admin) t.push({ label: 'Administrasi', value: 'admin', icon: 'pi pi-cog' });
    return t;
});
const aktif = ref(tampilan.value[0]?.value ?? null);

// ─── Info periode ───
const sisaCutOff = computed(() => hariKe(props.periode.cut_off));
const sisaCair = computed(() => hariKe(props.periode.target_cair));
const periodeTerkunci = computed(() => props.periode.status === 'terkunci');

const infoCutOff = computed(() => {
    const s = sisaCutOff.value;
    if (s === null) return null;
    if (periodeTerkunci.value) return { teks: 'Periode terkunci', severity: 'secondary', icon: 'pi pi-lock' };
    if (s < 0) return { teks: 'Cut-off terlewat', severity: 'danger', icon: 'pi pi-exclamation-circle' };
    if (s === 0) return { teks: 'Cut-off hari ini', severity: 'danger', icon: 'pi pi-clock' };
    if (s <= 3) return { teks: `Cut-off ${s} hari lagi`, severity: 'warn', icon: 'pi pi-clock' };
    return { teks: `Cut-off ${s} hari lagi`, severity: 'info', icon: 'pi pi-calendar' };
});

// ─── Katimker ───
const kt = computed(() => props.katimker);
const totalTugasKt = computed(() => {
    const s = kt.value?.status;
    return s ? s.draf + s.menunggu_bukti + s.diajukan + s.disetujui + s.ditolak : 0;
});
const perluTindakanKt = computed(() => (kt.value?.status.ditolak ?? 0) + (kt.value?.status.menunggu_bukti ?? 0));
const pctUtama = computed(() => persen(kt.value?.jam.utama_menit, kt.value?.jam.target_utama_menit));
const pctTambahan = computed(() => persen(kt.value?.jam.tambahan_menit, kt.value?.jam.target_tambahan_menit));
const datasetJamKt = computed(() => kt.value ? [
    { label: 'Tugas utama', data: kt.value.tren.series.utama, color: 'primary' },
    { label: 'Tugas tambahan', data: kt.value.tren.series.tambahan, color: 'cyan' },
] : []);

// ─── Kabid ───
const kb = computed(() => props.kabid);
const datasetStatusKb = computed(() => kb.value ? [
    { label: 'Disetujui', data: kb.value.tren.series.disetujui, color: 'green' },
    { label: 'Ditolak', data: kb.value.tren.series.ditolak, color: 'red' },
] : []);
const maxRekapBinaan = computed(() => Math.max(1, ...(kb.value?.rekap_binaan ?? []).map((r) => r.total_menit)));

// ─── Admin ───
const ad = computed(() => props.admin);
const totalPeriodeAd = computed(() => {
    const s = ad.value?.status_periode;
    return s ? s.draf + s.menunggu_bukti + s.diajukan + s.disetujui + s.ditolak : 0;
});
const pctDisetujuiAd = computed(() => totalPeriodeAd.value ? Math.round((ad.value.status_periode.disetujui / totalPeriodeAd.value) * 100) : null);
const datasetStatusAd = computed(() => ad.value ? [
    { label: 'Disetujui', data: ad.value.tren.series.disetujui, color: 'green' },
    { label: 'Ditolak', data: ad.value.tren.series.ditolak, color: 'red' },
    { label: 'Menunggu penilaian', data: ad.value.tren.series.diajukan, color: 'blue' },
] : []);

const labelAksi = {
    'mapping.update': 'Mengubah pemetaan approver',
};
function teksAksi(aksi) {
    return labelAksi[aksi] ?? aksi.replace(/[._]/g, ' ');
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="grid grid-cols-12 gap-6">
        <!-- ─── Header ─── -->
        <div class="col-span-12">
            <div class="card mb-0">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="min-w-0">
                        <h4 class="m-0 font-semibold text-2xl">{{ sapaan }}, {{ user?.nama ?? 'Pengguna' }}</h4>
                        <div class="flex flex-wrap items-center gap-2 mt-2 text-muted-color">
                            <span><i class="pi pi-calendar mr-1"></i>Periode {{ labelPeriode }}</span>
                            <Tag v-if="infoCutOff" :value="infoCutOff.teks" :severity="infoCutOff.severity" :icon="infoCutOff.icon" />
                            <span v-if="periode.target_cair && !periodeTerkunci && sisaCair !== null && sisaCair >= 0" class="text-sm">
                                · Target cair {{ formatTanggal(periode.target_cair) }}
                            </span>
                        </div>
                    </div>
                    <SelectButton
                        v-if="tampilan.length > 1"
                        v-model="aktif"
                        :options="tampilan"
                        optionLabel="label"
                        optionValue="value"
                        :allowEmpty="false"
                        aria-label="Pilih tampilan dashboard"
                    />
                </div>
            </div>
        </div>

        <!-- ═══════════════ KATIMKER ═══════════════ -->
        <template v-if="kt && aktif === 'katimker'">
            <div class="col-span-12">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h5 class="m-0 font-semibold text-lg">Kinerja Saya</h5>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="route('katimker.tugas.create')"><Button label="Tugas Baru" icon="pi pi-plus" size="small" /></Link>
                        <Link :href="route('katimker.tugas.index')"><Button label="Semua Tugas" icon="pi pi-list" size="small" severity="secondary" outlined /></Link>
                        <Link :href="route('katimker.rekap.index')"><Button label="Rekap" icon="pi pi-chart-bar" size="small" severity="secondary" outlined /></Link>
                    </div>
                </div>
            </div>

            <div class="col-span-12 md:col-span-6 xl:col-span-3">
                <KpiCard label="Jam Disetujui" :value="formatDurasi(kt.jam.total_menit)" icon="pi pi-clock" color="green"
                    :hint="`${totalTugasKt} tugas periode ini`" :href="route('katimker.rekap.index')" />
            </div>
            <div class="col-span-12 md:col-span-6 xl:col-span-3">
                <KpiCard label="Menunggu Penilaian" :value="kt.status.diajukan" icon="pi pi-send" color="blue"
                    hint="Sudah diajukan ke Kabid" :href="route('katimker.tugas.index')" />
            </div>
            <div class="col-span-12 md:col-span-6 xl:col-span-3">
                <KpiCard label="Perlu Tindakan" :value="perluTindakanKt" icon="pi pi-exclamation-triangle" :color="perluTindakanKt ? 'orange' : 'surface'"
                    :hint="perluTindakanKt ? `${kt.status.ditolak} ditolak · ${kt.status.menunggu_bukti} menunggu bukti` : 'Tidak ada yang tertunda'"
                    :alert="perluTindakanKt > 0" :href="route('katimker.tugas.index')" />
            </div>
            <div class="col-span-12 md:col-span-6 xl:col-span-3">
                <KpiCard label="Kehadiran" :value="kt.kehadiran?.tersedia ? kt.kehadiran.persen_kehadiran + '%' : '—'" icon="pi pi-user-edit" color="cyan"
                    :hint="kt.kehadiran?.tersedia ? `${kt.kehadiran.terlambat} terlambat · ${kt.kehadiran.tanpa_keterangan} tanpa ket.` : 'Data absensi belum tersedia'" />
            </div>

            <!-- Capaian target -->
            <div class="col-span-12 xl:col-span-5">
                <WidgetCard title="Capaian Target Jam" subtitle="Jam disetujui dibanding target periode ini" actionLabel="Detail" :actionHref="route('katimker.rekap.index')">
                    <div class="flex flex-col gap-6">
                        <ProgressRow label="Tugas Utama" color="primary" :percent="pctUtama"
                            :detail="kt.jam.target_utama_menit !== null ? `${formatDurasi(kt.jam.utama_menit)} dari ${formatDurasi(kt.jam.target_utama_menit)}` : 'Target belum diatur'" />
                        <ProgressRow label="Tugas Tambahan" color="cyan" :percent="pctTambahan"
                            :detail="kt.jam.target_tambahan_menit !== null ? `${formatDurasi(kt.jam.tambahan_menit)} dari ${formatDurasi(kt.jam.target_tambahan_menit)}` : 'Target belum diatur'" />
                    </div>

                    <div v-if="kt.insentif_terakhir" class="mt-6 pt-4 border-t border-surface flex items-center justify-between gap-3">
                        <div>
                            <span class="block text-sm text-muted-color">Insentif final terakhir · {{ namaBulan[kt.insentif_terakhir.bulan] }} {{ kt.insentif_terakhir.tahun }}</span>
                            <span class="font-semibold text-xl text-surface-900 dark:text-surface-0">{{ formatRupiah(kt.insentif_terakhir.nominal_insentif) }}</span>
                        </div>
                        <Tag :value="`${kt.insentif_terakhir.persen_total}%`" severity="success" />
                    </div>
                </WidgetCard>
            </div>

            <!-- Perlu tindakan -->
            <div class="col-span-12 xl:col-span-7">
                <WidgetCard title="Perlu Ditindaklanjuti" subtitle="Ditolak, menunggu bukti, atau masih draf" actionLabel="Lihat semua" :actionHref="route('katimker.tugas.index')">
                    <ul v-if="kt.perlu_tindakan.length" class="list-none p-0 m-0 flex flex-col divide-y divide-surface-200 dark:divide-surface-700">
                        <li v-for="t in kt.perlu_tindakan" :key="t.id" class="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="min-w-0">
                                <div class="font-medium text-surface-900 dark:text-surface-0 truncate">{{ t.judul }}</div>
                                <div class="text-sm text-muted-color">{{ formatTanggal(t.tanggal) }} · {{ jenisLabel[t.jenis] }}</div>
                                <div v-if="t.status === 'ditolak' && t.catatan_penilai" class="text-sm text-red-500 mt-1">
                                    <i class="pi pi-info-circle"></i> {{ t.catatan_penilai }}
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <Tag :value="statusMeta[t.status].label" :severity="statusMeta[t.status].severity" />
                                <Link :href="route('katimker.tugas.edit', t.id)"><Button icon="pi pi-pencil" size="small" rounded outlined aria-label="Ubah tugas" /></Link>
                            </div>
                        </li>
                    </ul>
                    <div v-else class="flex flex-col items-center justify-center text-center py-8 text-muted-color">
                        <i class="pi pi-check-circle text-3xl text-green-500 mb-3"></i>
                        <span>Semua tugas sudah beres. Tidak ada yang perlu ditindaklanjuti.</span>
                    </div>
                </WidgetCard>
            </div>

            <!-- Tren -->
            <div class="col-span-12 xl:col-span-7">
                <WidgetCard title="Tren Jam Disetujui" subtitle="6 bulan terakhir, dalam jam">
                    <TrendChart :labels="kt.tren.labels" :datasets="datasetJamKt" stacked unit="jam" />
                </WidgetCard>
            </div>

            <!-- Aktivitas -->
            <div class="col-span-12 xl:col-span-5">
                <WidgetCard title="Tugas Terbaru" subtitle="Lima entri terakhir" actionLabel="Semua tugas" :actionHref="route('katimker.tugas.index')">
                    <ul v-if="kt.terbaru.length" class="list-none p-0 m-0 flex flex-col gap-4">
                        <li v-for="t in kt.terbaru" :key="t.id" class="flex items-start gap-3">
                            <div class="w-10 h-10 flex items-center justify-center rounded-full shrink-0"
                                :class="{
                                    'bg-green-100 dark:bg-green-400/10': t.status === 'disetujui',
                                    'bg-red-100 dark:bg-red-400/10': t.status === 'ditolak',
                                    'bg-blue-100 dark:bg-blue-400/10': t.status === 'diajukan',
                                    'bg-orange-100 dark:bg-orange-400/10': t.status === 'menunggu_bukti',
                                    'bg-surface-100 dark:bg-surface-700': t.status === 'draf',
                                }">
                                <i class="pi" :class="{
                                    'pi-check text-green-600 dark:text-green-400': t.status === 'disetujui',
                                    'pi-times text-red-600 dark:text-red-400': t.status === 'ditolak',
                                    'pi-send text-blue-600 dark:text-blue-400': t.status === 'diajukan',
                                    'pi-camera text-orange-600 dark:text-orange-400': t.status === 'menunggu_bukti',
                                    'pi-file-edit text-surface-500': t.status === 'draf',
                                }"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <span class="font-medium text-surface-900 dark:text-surface-0 text-sm block truncate">{{ t.judul }}</span>
                                <span class="text-muted-color text-xs block mt-1">
                                    {{ formatTanggal(t.tanggal) }} · {{ statusMeta[t.status].label }} · {{ formatDurasi(t.durasi_disetujui_menit ?? t.durasi_diajukan_menit) }}
                                </span>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-muted-color m-0">Belum ada tugas. Mulai dengan membuat tugas baru.</p>
                </WidgetCard>
            </div>
        </template>

        <!-- ═══════════════ KABID ═══════════════ -->
        <template v-if="kb && aktif === 'kabid'">
            <div class="col-span-12">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h5 class="m-0 font-semibold text-lg">Penilaian &amp; Binaan</h5>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="route('kabid.penilaian.index')"><Button label="Mulai Menilai" icon="pi pi-check-square" size="small" /></Link>
                        <Link :href="route('kabid.penugasan.create')"><Button label="Beri Penugasan" icon="pi pi-plus-circle" size="small" severity="secondary" outlined /></Link>
                        <Link :href="route('kabid.rekap.index')"><Button label="Rekap Binaan" icon="pi pi-chart-bar" size="small" severity="secondary" outlined /></Link>
                    </div>
                </div>
            </div>

            <div class="col-span-12 md:col-span-6 xl:col-span-3">
                <KpiCard label="Menunggu Penilaian" :value="kb.menunggu" icon="pi pi-check-square" :color="kb.menunggu ? 'blue' : 'surface'"
                    :hint="kb.menumpuk ? `${kb.menumpuk} lebih dari ${kb.hari_menumpuk} hari` : 'Tidak ada yang menumpuk'"
                    :alert="kb.menumpuk > 0" :href="route('kabid.penilaian.index')" />
            </div>
            <div class="col-span-12 md:col-span-6 xl:col-span-3">
                <KpiCard label="Disetujui Bulan Ini" :value="kb.status_bulan_ini.disetujui" icon="pi pi-check-circle" color="green" hint="Tugas yang Anda setujui" />
            </div>
            <div class="col-span-12 md:col-span-6 xl:col-span-3">
                <KpiCard label="Ditolak Bulan Ini" :value="kb.status_bulan_ini.ditolak" icon="pi pi-times-circle" color="red" hint="Dikembalikan ke Katimker" />
            </div>
            <div class="col-span-12 md:col-span-6 xl:col-span-3">
                <KpiCard label="Katimker Binaan" :value="kb.jumlah_binaan" icon="pi pi-users" color="purple"
                    :hint="`${kb.binaan_aktif_bulan_ini} punya jam disetujui bulan ini`" :href="route('kabid.rekap.index')" />
            </div>

            <!-- Antrean -->
            <div class="col-span-12 xl:col-span-7">
                <WidgetCard title="Antrean Penilaian" subtitle="Diurutkan dari yang paling lama menunggu" actionLabel="Semua antrean" :actionHref="route('kabid.penilaian.index')">
                    <ul v-if="kb.antrean.length" class="list-none p-0 m-0 flex flex-col divide-y divide-surface-200 dark:divide-surface-700">
                        <li v-for="t in kb.antrean" :key="t.id" class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="min-w-0">
                                <div class="font-medium text-surface-900 dark:text-surface-0 truncate">{{ t.judul }}</div>
                                <div class="text-sm text-muted-color truncate">
                                    {{ t.katimker_nama }} · {{ jenisLabel[t.jenis] }} · {{ formatDurasi(t.durasi_diajukan_menit) }}
                                </div>
                                <div class="text-xs text-muted-color mt-1">Diajukan {{ waktuRelatif(t.diajukan_pada) }}</div>
                            </div>
                            <Link :href="route('kabid.penilaian.show', t.id)"><Button label="Nilai" size="small" icon="pi pi-check-square" /></Link>
                        </li>
                    </ul>
                    <div v-else class="flex flex-col items-center justify-center text-center py-8 text-muted-color">
                        <i class="pi pi-check-circle text-3xl text-green-500 mb-3"></i>
                        <span>Tidak ada tugas yang menunggu penilaian.</span>
                    </div>
                </WidgetCard>
            </div>

            <!-- Rekap binaan -->
            <div class="col-span-12 xl:col-span-5">
                <WidgetCard title="Jam Disetujui Binaan" :subtitle="`Periode ${labelPeriode}`" actionLabel="Rekap lengkap" :actionHref="route('kabid.rekap.index')">
                    <div v-if="kb.rekap_binaan.length" class="flex flex-col gap-5">
                        <ProgressRow v-for="r in kb.rekap_binaan" :key="r.nama" :label="r.nama" color="primary"
                            :percent="Math.round((r.total_menit / maxRekapBinaan) * 100)" :detail="formatDurasi(r.total_menit)" />
                    </div>
                    <p v-else class="text-muted-color m-0">Belum ada Katimker binaan. Hubungi administrator untuk pemetaan.</p>
                </WidgetCard>
            </div>

            <div class="col-span-12">
                <WidgetCard title="Tren Penilaian" subtitle="Jumlah tugas binaan Anda yang disetujui dan ditolak, 6 bulan terakhir">
                    <TrendChart :labels="kb.tren.labels" :datasets="datasetStatusKb" unit="tugas" height="260px" />
                </WidgetCard>
            </div>
        </template>

        <!-- ═══════════════ ADMIN ═══════════════ -->
        <template v-if="ad && aktif === 'admin'">
            <div class="col-span-12">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h5 class="m-0 font-semibold text-lg">Ringkasan Administrasi</h5>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="route('admin.insentif.hitung.index')"><Button label="Hitung Insentif" icon="pi pi-calculator" size="small" /></Link>
                        <Link :href="route('admin.rekap.index')"><Button label="Rekap Kinerja" icon="pi pi-chart-bar" size="small" severity="secondary" outlined /></Link>
                        <Link :href="route('admin.pengguna.index')"><Button label="Pengguna" icon="pi pi-users" size="small" severity="secondary" outlined /></Link>
                    </div>
                </div>
            </div>

            <div v-if="ad.katimker_belum_dipetakan > 0" class="col-span-12">
                <Message severity="warn" :closable="false">
                    Ada {{ ad.katimker_belum_dipetakan }} Katimker belum dipetakan ke Kabid, tugas mereka belum bisa dinilai.
                    <Link :href="route('admin.pemetaan.index')" class="text-primary font-medium ml-1 hover:underline">Atur pemetaan</Link>
                </Message>
            </div>

            <div class="col-span-12 md:col-span-6 xl:col-span-3">
                <KpiCard label="Menunggu Penilaian" :value="ad.total_diajukan" icon="pi pi-send" color="blue"
                    :hint="ad.menumpuk ? `${ad.menumpuk} lebih dari ${ad.hari_menumpuk} hari` : 'Tidak ada yang menumpuk'" :alert="ad.menumpuk > 0" />
            </div>
            <div class="col-span-12 md:col-span-6 xl:col-span-3">
                <KpiCard label="Disetujui Periode Ini" :value="ad.status_periode.disetujui" icon="pi pi-check-circle" color="green"
                    :hint="pctDisetujuiAd !== null ? `${pctDisetujuiAd}% dari ${totalPeriodeAd} tugas` : 'Belum ada tugas'" />
            </div>
            <div class="col-span-12 md:col-span-6 xl:col-span-3">
                <KpiCard label="Ditolak Periode Ini" :value="ad.status_periode.ditolak" icon="pi pi-times-circle" color="red" hint="Perlu perbaikan Katimker" />
            </div>
            <div class="col-span-12 md:col-span-6 xl:col-span-3">
                <KpiCard label="Insentif Difinalkan" :value="`${ad.insentif_final} / ${ad.jumlah_katimker}`" icon="pi pi-wallet" color="purple"
                    hint="Katimker periode ini" :href="route('admin.insentif.hitung.index')" />
            </div>

            <div class="col-span-12 xl:col-span-8">
                <WidgetCard title="Tren Tugas" subtitle="Seluruh Katimker, 6 bulan terakhir">
                    <TrendChart :labels="ad.tren.labels" :datasets="datasetStatusAd" stacked unit="tugas" />
                </WidgetCard>
            </div>

            <div class="col-span-12 xl:col-span-4">
                <WidgetCard title="Pengguna Aktif" subtitle="Berdasarkan peran" actionLabel="Kelola" :actionHref="route('admin.pengguna.index')">
                    <ul class="list-none p-0 m-0 flex flex-col gap-4">
                        <li class="flex items-center justify-between">
                            <span class="flex items-center gap-3"><i class="pi pi-list-check text-primary"></i> Katimker</span>
                            <span class="font-semibold text-surface-900 dark:text-surface-0">{{ ad.pengguna_aktif.katimker }}</span>
                        </li>
                        <li class="flex items-center justify-between">
                            <span class="flex items-center gap-3"><i class="pi pi-check-square text-primary"></i> Kabid</span>
                            <span class="font-semibold text-surface-900 dark:text-surface-0">{{ ad.pengguna_aktif.kabid }}</span>
                        </li>
                        <li class="flex items-center justify-between">
                            <span class="flex items-center gap-3"><i class="pi pi-cog text-primary"></i> Admin</span>
                            <span class="font-semibold text-surface-900 dark:text-surface-0">{{ ad.pengguna_aktif.admin }}</span>
                        </li>
                    </ul>
                    <div class="mt-6 pt-4 border-t border-surface">
                        <ProgressRow label="Katimker terpetakan" color="green"
                            :percent="ad.jumlah_katimker ? Math.round(((ad.jumlah_katimker - ad.katimker_belum_dipetakan) / ad.jumlah_katimker) * 100) : null"
                            :detail="`${ad.jumlah_katimker - ad.katimker_belum_dipetakan} dari ${ad.jumlah_katimker}`" />
                    </div>
                </WidgetCard>
            </div>

            <div class="col-span-12">
                <WidgetCard title="Aktivitas Terbaru" subtitle="Dari log audit sistem" actionLabel="Log audit" :actionHref="route('admin.audit.index')">
                    <ul v-if="ad.aktivitas.length" class="list-none p-0 m-0 flex flex-col gap-4">
                        <li v-for="a in ad.aktivitas" :key="a.id" class="flex items-start gap-3">
                            <div class="w-10 h-10 flex items-center justify-center rounded-full bg-blue-100 dark:bg-blue-400/10 shrink-0">
                                <i class="pi pi-history text-blue-600 dark:text-blue-400"></i>
                            </div>
                            <div class="min-w-0">
                                <span class="font-medium text-surface-900 dark:text-surface-0 text-sm block">{{ a.aktor }}</span>
                                <span class="text-muted-color text-sm">{{ teksAksi(a.aksi) }}</span>
                                <span class="text-muted-color text-xs block mt-1">{{ waktuRelatif(a.waktu) }}</span>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-muted-color m-0">Belum ada aktivitas tercatat.</p>
                </WidgetCard>
            </div>
        </template>

        <!-- Tanpa peran -->
        <div v-if="!tampilan.length" class="col-span-12">
            <div class="card">
                <p class="text-muted-color m-0">Akun Anda belum memiliki peran kinerja. Hubungi administrator.</p>
            </div>
        </div>
    </div>
</template>
