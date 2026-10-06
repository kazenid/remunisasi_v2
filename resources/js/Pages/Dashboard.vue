<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineOptions({ layout: AppLayout });

const props = defineProps({
    periode: { type: Object, default: () => ({}) },
    rekap: { type: Object, default: () => ({}) },
    recentTasks: { type: Array, default: () => [] },
    pendingApprovalsCount: { type: Number, default: 0 },
});

const formatCurrency = (val) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(val || 0);

const getStatusSeverity = (status) => {
    switch (status) {
        case 'disetujui_direktur':
        case 'disetujui_wadir':
        case 'disetujui_kabid':
        case 'disetujui_katker': return 'success';
        case 'diajukan': return 'info';
        case 'revisi': return 'warn';
        case 'ditolak': return 'danger';
        default: return 'secondary';
    }
};

const formatStatus = (status) => {
    switch (status) {
        case 'disetujui_direktur': return 'Disetujui Final';
        case 'disetujui_wadir': return 'Disetujui Wadir';
        case 'disetujui_kabid': return 'Disetujui Kabid';
        case 'disetujui_katker': return 'Disetujui Katker';
        case 'diajukan': return 'Menunggu Verifikasi';
        case 'revisi': return 'Perlu Revisi';
        case 'ditolak': return 'Ditolak';
        default: return 'Draft';
    }
};
</script>

<template>
    <Head title="Dashboard Remunerasi" />

    <div class="grid grid-cols-12 gap-6">
        <!-- Approver Alert -->
        <div v-if="pendingApprovalsCount > 0" class="col-span-12">
            <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500 text-white flex items-center justify-center font-bold">
                        <i class="pi pi-bell text-lg"></i>
                    </div>
                    <div>
                        <div class="font-bold text-amber-900 dark:text-amber-300">Menunggu Verifikasi Kinerja</div>
                        <div class="text-xs text-amber-800 dark:text-amber-400">Terdapat {{ pendingApprovalsCount }} tugas staf yang butuh verifikasi Anda.</div>
                    </div>
                </div>
                <Link :href="route('approval.index')" class="p-button p-button-sm p-button-warning text-xs font-semibold">
                    Verifikasi Sekarang
                </Link>
            </div>
        </div>

        <!-- Metric 1: Tugas Utama (50%) -->
        <div class="col-span-12 md:col-span-6 lg:col-span-3">
            <div class="card mb-0 h-full flex flex-col justify-between">
                <div class="flex justify-between mb-3">
                    <div>
                        <span class="block text-muted-color font-medium text-sm mb-1">Tugas Utama (50%)</span>
                        <div class="text-surface-900 dark:text-surface-0 font-bold text-2xl">
                            {{ rekap.jam_utama_disetujui || 0 }} / {{ rekap.target_jam_utama || 150 }} <span class="text-xs text-muted-color font-normal">Jam</span>
                        </div>
                    </div>
                    <div class="flex items-center justify-center bg-blue-100 dark:bg-blue-400/10 rounded-xl w-10 h-10">
                        <i class="pi pi-clock text-blue-500 text-xl"></i>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="text-muted-color">Capaian:</span>
                        <span class="font-semibold text-blue-500">{{ rekap.capaian_jam_utama || 0 }}%</span>
                    </div>
                    <ProgressBar :value="Math.min(100, Number(rekap.capaian_jam_utama || 0))" :showValue="false" style="height: 6px" />
                    <div class="text-[11px] text-muted-color mt-2">Skor: <strong>{{ rekap.skor_utama || 0 }}%</strong></div>
                </div>
            </div>
        </div>

        <!-- Metric 2: Tugas Tambahan (10%) -->
        <div class="col-span-12 md:col-span-6 lg:col-span-3">
            <div class="card mb-0 h-full flex flex-col justify-between">
                <div class="flex justify-between mb-3">
                    <div>
                        <span class="block text-muted-color font-medium text-sm mb-1">Tugas Tambahan (10%)</span>
                        <div class="text-surface-900 dark:text-surface-0 font-bold text-2xl">
                            {{ rekap.jam_tambahan_disetujui || 0 }} / {{ rekap.target_jam_tambahan || 15 }} <span class="text-xs text-muted-color font-normal">Jam</span>
                        </div>
                    </div>
                    <div class="flex items-center justify-center bg-purple-100 dark:bg-purple-400/10 rounded-xl w-10 h-10">
                        <i class="pi pi-star text-purple-500 text-xl"></i>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="text-muted-color">Capaian:</span>
                        <span class="font-semibold text-purple-500">{{ rekap.capaian_jam_tambahan || 0 }}%</span>
                    </div>
                    <ProgressBar :value="Math.min(100, Number(rekap.capaian_jam_tambahan || 0))" :showValue="false" style="height: 6px" />
                    <div class="text-[11px] text-muted-color mt-2">Skor: <strong>{{ rekap.skor_tambahan || 0 }}%</strong></div>
                </div>
            </div>
        </div>

        <!-- Metric 3: Presensi (40%) -->
        <div class="col-span-12 md:col-span-6 lg:col-span-3">
            <div class="card mb-0 h-full flex flex-col justify-between">
                <div class="flex justify-between mb-3">
                    <div>
                        <span class="block text-muted-color font-medium text-sm mb-1">Presensi SIKEP (40%)</span>
                        <div class="text-surface-900 dark:text-surface-0 font-bold text-2xl">
                            {{ rekap.persentase_kehadiran || 100 }}%
                        </div>
                    </div>
                    <div class="flex items-center justify-center bg-teal-100 dark:bg-teal-400/10 rounded-xl w-10 h-10">
                        <i class="pi pi-calendar-check text-teal-500 text-xl"></i>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="text-muted-color">Kehadiran:</span>
                        <span class="font-semibold text-teal-500">{{ rekap.persentase_kehadiran || 100 }}%</span>
                    </div>
                    <ProgressBar :value="Number(rekap.persentase_kehadiran || 100)" :showValue="false" style="height: 6px" />
                    <div class="text-[11px] text-muted-color mt-2">Skor: <strong>{{ rekap.skor_kehadiran || 40 }}%</strong></div>
                </div>
            </div>
        </div>

        <!-- Metric 4: Total Insentif -->
        <div class="col-span-12 md:col-span-6 lg:col-span-3">
            <div class="card mb-0 h-full flex flex-col justify-between bg-primary/5 border border-primary/20">
                <div class="flex justify-between mb-3">
                    <div>
                        <span class="block text-primary font-medium text-sm mb-1">Estimasi Insentif ({{ rekap.total_persen_kinerja || 0 }}%)</span>
                        <div class="text-surface-900 dark:text-surface-0 font-bold text-xl lg:text-2xl text-primary">
                            {{ formatCurrency(rekap.nominal_insentif) }}
                        </div>
                    </div>
                    <div class="flex items-center justify-center bg-primary text-white rounded-xl w-10 h-10">
                        <i class="pi pi-wallet text-xl"></i>
                    </div>
                </div>
                <div>
                    <div class="text-xs text-muted-color">Periode: <strong>{{ periode.nama || 'Bulan Berjalan' }}</strong></div>
                    <div class="text-[11px] text-muted-color mt-1">Dasar Plafon: {{ formatCurrency(rekap.nominal_dasar) }}</div>
                </div>
            </div>
        </div>

        <!-- Tasks List & Guidelines -->
        <div class="col-span-12 xl:col-span-8">
            <div class="card">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <div class="font-semibold text-lg text-surface-900 dark:text-surface-0">Aktivitas Tugas Harian Terakhir</div>
                        <span class="text-xs text-muted-color">Log harian mandiri dengan geolokasi bukti</span>
                    </div>
                    <Link :href="route('tugas.index')" class="p-button p-button-sm p-button-outlined">
                        <i class="pi pi-plus mr-1"></i> Catat Tugas
                    </Link>
                </div>

                <div v-if="recentTasks.length === 0" class="text-center py-10 text-muted-color">
                    <i class="pi pi-inbox text-4xl mb-3 block"></i>
                    <p class="text-sm">Belum ada log tugas harian untuk periode ini.</p>
                    <Link :href="route('tugas.index')" class="text-primary text-xs font-semibold hover:underline mt-1 inline-block">
                        Mulai mencatat tugas hari ini &rarr;
                    </Link>
                </div>

                <div v-else class="space-y-3">
                    <div v-for="task in recentTasks" :key="task.id" class="p-3 rounded-xl border border-surface-200 dark:border-surface-700 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors">
                        <div class="flex items-start gap-3">
                            <div :class="task.kategori === 'utama' ? 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300' : 'bg-purple-100 text-purple-600 dark:bg-purple-900/40 dark:text-purple-300'" class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 font-bold text-xs uppercase">
                                {{ task.kategori === 'utama' ? 'UTM' : 'TMB' }}
                            </div>
                            <div>
                                <div class="font-semibold text-sm text-surface-900 dark:text-surface-0">{{ task.judul }}</div>
                                <div class="text-xs text-muted-color flex items-center gap-2 mt-0.5">
                                    <span><i class="pi pi-calendar text-[10px] mr-1"></i>{{ task.tanggal }}</span>
                                    <span>&bull;</span>
                                    <span><i class="pi pi-clock text-[10px] mr-1"></i>{{ task.durasi_jam }} Jam</span>
                                    <span v-if="task.bukti && task.bukti.length > 0" class="text-green-600 dark:text-green-400">
                                        <i class="pi pi-image text-[10px] mr-1"></i>Foto Bukti
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 self-end sm:self-center">
                            <Tag :value="formatStatus(task.status)" :severity="getStatusSeverity(task.status)" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-span-12 xl:col-span-4">
            <div class="card">
                <div class="font-semibold text-base mb-3 text-surface-900 dark:text-surface-0">Skema Penilaian Kinerja</div>
                <div class="space-y-4 text-xs">
                    <div class="p-3 rounded-xl bg-surface-50 dark:bg-surface-800/50 border border-surface-200 dark:border-surface-700">
                        <div class="flex items-center justify-between font-semibold mb-1">
                            <span>1. Presensi SIKEP</span>
                            <span class="text-teal-600 font-bold">Bobot 40%</span>
                        </div>
                        <p class="text-muted-color">Terintegrasi otomatis dengan mesin finger/aplikasi presensi SIKEP.</p>
                    </div>

                    <div class="p-3 rounded-xl bg-surface-50 dark:bg-surface-800/50 border border-surface-200 dark:border-surface-700">
                        <div class="flex items-center justify-between font-semibold mb-1">
                            <span>2. Tugas Pokok / Utama</span>
                            <span class="text-blue-600 font-bold">Bobot 50%</span>
                        </div>
                        <p class="text-muted-color">Target standar <strong>150 Jam/bulan</strong>. Capaian jam disetujui proporsional terhadap target.</p>
                    </div>

                    <div class="p-3 rounded-xl bg-surface-50 dark:bg-surface-800/50 border border-surface-200 dark:border-surface-700">
                        <div class="flex items-center justify-between font-semibold mb-1">
                            <span>3. Tugas Tambahan</span>
                            <span class="text-purple-600 font-bold">Bobot 10%</span>
                        </div>
                        <p class="text-muted-color">Target standar <strong>15 Jam/bulan</strong> (kepanitiaan, pokja, penugasan khusus).</p>
                    </div>

                    <div class="text-[11px] text-muted-color border-t border-surface-200 dark:border-surface-700 pt-3">
                        Total Skor Maksimal: <strong>100%</strong>. Tanggal Cut-off: <strong>Tgl {{ periode.tgl_cutoff || 10 }}</strong>.
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
