<?php

namespace App\Models;

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

    public const STATUS_DRAF = 'draf';

    public const STATUS_DIAJUKAN = 'diajukan';

    public const STATUS_DISETUJUI = 'disetujui';

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

    public function isDraf(): bool
    {
        return $this->status === self::STATUS_DRAF;
    }

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

    /** Dapat diedit oleh Katimker: draf, ditolak, atau menunggu bukti (penugasan Kabid). */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAF, self::STATUS_DITOLAK, self::STATUS_MENUNGGU_BUKTI], true);
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
        return $q->whereYear('tanggal', $tahun)->whereMonth('tanggal', $bulan);
    }
}
