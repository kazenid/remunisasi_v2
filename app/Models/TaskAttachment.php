<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lampiran bukti sebuah tugas (foto selfie wajib / dokumen opsional).
 *
 * @property int $id
 * @property int $task_report_id
 * @property string $tipe
 * @property string $path
 * @property string $nama_asli
 * @property string $mime
 * @property int $ukuran
 */
class TaskAttachment extends Model
{
    public const TIPE_FOTO_SELFIE = 'foto_selfie';

    public const TIPE_DOKUMEN = 'dokumen';

    protected $fillable = [
        'task_report_id',
        'tipe',
        'path',
        'nama_asli',
        'mime',
        'ukuran',
        'diambil_pada',
    ];

    /** Atribut turunan untuk kebutuhan tampilan (mode edit). */
    protected $appends = ['url', 'is_image', 'ukuran_label'];

    protected function casts(): array
    {
        return [
            'ukuran' => 'integer',
            'diambil_pada' => 'datetime',
        ];
    }

    public function taskReport(): BelongsTo
    {
        return $this->belongsTo(TaskReport::class);
    }

    /** URL aman untuk membuka bukti via route terotorisasi. */
    public function getUrlAttribute(): string
    {
        return route('bukti.show', $this->id);
    }

    /** Apakah lampiran ini berupa gambar (untuk preview thumbnail). */
    public function getIsImageAttribute(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /** Ukuran berkas dalam format manusiawi (B/KB/MB). */
    public function getUkuranLabelAttribute(): string
    {
        $bytes = (int) $this->ukuran;

        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
