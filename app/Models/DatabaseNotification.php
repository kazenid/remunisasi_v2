<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification as BaseDatabaseNotification;

/**
 * Model notifikasi kustom: sama dengan tabel `notifications` bawaan Laravel,
 * namun menulis `created_at`/`read_at` dengan presisi mikrodetik agar urutan
 * `latest()` deterministik meski PK berupa UUID acak (desain §3.1).
 *
 * Write-path invariant (desain §3.1 R4-3): JANGAN menonaktifkan $timestamps
 * dan JANGAN meng-override penanganan created_at — presisi mikrodetik ditulis
 * SEMATA-MATA oleh Eloquent melalui $dateFormat di bawah.
 */
class DatabaseNotification extends BaseDatabaseNotification
{
    protected $dateFormat = 'Y-m-d H:i:s.u';
}
