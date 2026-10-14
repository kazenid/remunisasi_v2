<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Entri tugas kinerja harian milik Katimker.
 *
 * @property int $id
 * @property int|null $period_id
 * @property int $katimker_sikep_user_id
 * @property int|null $kabid_sikep_user_id
 * @property string $jenis
 * @property string $status
 * @property int $durasi_diajukan_menit
 * @property int|null $durasi_disetujui_menit
 */
class TaskReport extends Model
{
    // Status
    public const STATUS_MENUNGGU_BUKTI = 'menunggu_bukti';

    /** Tersimpan & muncul di penilai ("proses penilaian"). */
    public const STATUS_DIAJUKAN = 'diajukan';

    public const STATUS_DISETUJUI = 'disetujui';

    /** Dikembalikan penilai; Katimker dapat memperbaiki dan menyimpan ulang. */
    public const STATUS_REVISI = 'revisi';

    /** Final: tidak dapat diajukan kembali. */
    public const STATUS_DITOLAK = 'ditolak';

    // Jenis
    public const JENIS_UTAMA = 'utama';

    public const JENIS_TAMBAHAN = 'tambahan';

    protected $fillable = [
        'period_id',
        'katimker_sikep_user_id',
        'kabid_sikep_user_id',
        'dibuat_oleh_sikep_user_id',
        'jenis_terkunci',
        'tanggal',
        'jenis',
        'kegiatan_id',
        'judul',
        'uraian',
        'durasi_diajukan_menit',
        'durasi_disetujui_menit',
        'status',
        'catatan_penilai',
        'alasan_pengurangan',
        'lat',
        'lng',
        'akurasi',
        'diajukan_pada',
        'dinilai_pada',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'jenis_terkunci' => 'boolean',
            'durasi_diajukan_menit' => 'integer',
            'durasi_disetujui_menit' => 'integer',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'akurasi' => 'float',
            'diajukan_pada' => 'datetime',
            'dinilai_pada' => 'datetime',
        ];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    // ── Helper status ────────────────────────────────────────────────

    public function isDiajukan(): bool
    {
        return $this->status === self::STATUS_DIAJUKAN;
    }

    public function isDisetujui(): bool
    {
        return $this->status === self::STATUS_DISETUJUI;
    }

    public function isDitolak(): bool
    {
        return $this->status === self::STATUS_DITOLAK;
    }

    public function isRevisi(): bool
    {
        return $this->status === self::STATUS_REVISI;
    }

    /**
     * Dapat diedit oleh Katimker selama belum disetujui/ditolak:
     * proses penilaian (diajukan), revisi, atau menunggu bukti (penugasan Kabid).
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DIAJUKAN, self::STATUS_REVISI, self::STATUS_MENUNGGU_BUKTI], true);
    }

    /** Periode tanggal tugas sudah melewati cut-off / dikunci admin. */
    public function periodeTerkunci(): bool
    {
        return Period::lockedFor((int) $this->tanggal->year, (int) $this->tanggal->month);
    }

    public function isMenungguBukti(): bool
    {
        return $this->status === self::STATUS_MENUNGGU_BUKTI;
    }

    public function hasSelfie(): bool
    {
        return $this->attachments()
            ->where('tipe', TaskAttachment::TIPE_FOTO_SELFIE)
            ->exists();
    }

    public function kegiatan(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'kegiatan_id');
    }

    /**
     * Peringatan bukti/validasi untuk penilai (bukan pemblokir).
     *
     * @return array<int,string>
     */
    public function peringatanBukti(): array
    {
        $lampiran = $this->relationLoaded('attachments') ? $this->attachments : $this->attachments()->get();
        $p = [];

        if (! $lampiran->contains('tipe', TaskAttachment::TIPE_FOTO_SELFIE)) {
            $p[] = 'Belum ada foto selfie sebagai bukti.';
        }
        if ($lampiran->isEmpty()) {
            $p[] = 'Tidak ada lampiran bukti sama sekali.';
        }
        if ($this->durasi_diajukan_menit <= 0) {
            $p[] = 'Durasi diajukan masih 0 (belum diisi Katimker).';
        }

        return $p;
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeMilik(Builder $q, int $katimkerSikepUserId): Builder
    {
        return $q->where('katimker_sikep_user_id', $katimkerSikepUserId);
    }

    public function scopeUntukKabid(Builder $q, int $kabidSikepUserId): Builder
    {
        return $q->where('kabid_sikep_user_id', $kabidSikepUserId);
    }

    public function scopePeriode(Builder $q, int $tahun, int $bulan): Builder
    {
        // Rentang tanggal (bukan whereYear/whereMonth) agar indeks `tanggal` dapat dipakai.
        $awal = Carbon::create($tahun, $bulan, 1);

        return $q->whereBetween('tanggal', [$awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()]);
    }
}
