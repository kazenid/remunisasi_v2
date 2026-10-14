// Helper format tampilan yang dipakai lintas halaman (dashboard, daftar tugas, rekap).

export const namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
export const namaBulanPendek = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

export function formatDurasi(menit) {
    const m = Number(menit) || 0;
    const j = Math.floor(m / 60);
    const s = m % 60;
    return [j ? `${j} jam` : null, s ? `${s} menit` : null].filter(Boolean).join(' ') || '0 menit';
}

export function formatTanggal(t) {
    if (!t) return '—';
    return new Date(t).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

export function formatRupiah(n) {
    if (n === null || n === undefined) return '—';
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n);
}

/** "2026-03" → "Mar 26" */
export function labelBulanTren(ym) {
    const [y, m] = String(ym).split('-');
    return `${namaBulanPendek[Number(m)] ?? ym} ${String(y).slice(2)}`;
}

/** Waktu relatif singkat dalam Bahasa Indonesia. */
export function waktuRelatif(iso) {
    if (!iso) return '';
    const selisih = (Date.now() - new Date(iso).getTime()) / 1000;
    if (selisih < 60) return 'baru saja';
    if (selisih < 3600) return `${Math.floor(selisih / 60)} menit lalu`;
    if (selisih < 86400) return `${Math.floor(selisih / 3600)} jam lalu`;
    if (selisih < 86400 * 30) return `${Math.floor(selisih / 86400)} hari lalu`;
    return formatTanggal(iso);
}

/** Selisih hari (pembulatan ke atas) dari hari ini ke tanggal; negatif bila sudah lewat. */
export function hariKe(tanggal) {
    if (!tanggal) return null;
    const hariIni = new Date();
    hariIni.setHours(0, 0, 0, 0);
    const target = new Date(`${tanggal}T00:00:00`);
    return Math.round((target - hariIni) / 86400000);
}

export function persen(nilai, target) {
    if (!target) return null;
    return Math.min(100, Math.round((nilai / target) * 100));
}

export const statusMeta = {
    menunggu_bukti: { label: 'Menunggu Bukti', severity: 'warn' },
    diajukan: { label: 'Proses Penilaian', severity: 'info' },
    revisi: { label: 'Revisi', severity: 'warn' },
    disetujui: { label: 'Disetujui', severity: 'success' },
    ditolak: { label: 'Ditolak', severity: 'danger' },
};

/** Status yang masih dapat diubah Katimker (selama periode belum melewati cut-off). */
export const statusDapatDiedit = ['diajukan', 'revisi', 'menunggu_bukti'];

export const jenisLabel = { utama: 'Utama', tambahan: 'Tambahan' };
