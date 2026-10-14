<?php

namespace Database\Seeders;

use App\Models\Activity;
use Illuminate\Database\Seeder;

/** Master kegiatan awal. Idempotent: nama yang sudah ada tidak diubah (status aktif tetap). */
class ActivitySeeder extends Seeder
{
    private const KEGIATAN = [
        'Melaksanakan',
        'Melaporkan',
        'Memberi Arahan',
        'Memimpin',
        'Memonitoring',
        'Mempelajari',
        'Menandatangani',
        'Mendampingi',
        'Menelaah Dokumen',
        'Meneliti',
        'Menerima Tamu',
        'Menjadi Narasumber',
        'Menjadi Mentor/CI',
        'Menganalisi',
        'Mengevaluasi',
        'Menghadiri',
        'Mengikuti Apel',
        'Mengikuti Rapat',
        'Mengikuti Upacara',
        'Mengklarifikasi',
        'Mengkoordinasikan',
        'Menyusun',
        'Merencanakan',
        'Merumuskan',
    ];

    public function run(): void
    {
        foreach (self::KEGIATAN as $nama) {
            Activity::firstOrCreate(['nama' => $nama], ['aktif' => true]);
        }
    }
}
