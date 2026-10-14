<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Data awal yang dibutuhkan aplikasi. Seeder idempotent (aman dijalankan ulang).
     *
     * Pengguna TIDAK di-seed: akun berasal dari SIKEP (tarik lewat menu Pengguna),
     * admin pertama dibuat dengan `php artisan app:set-admin`.
     */
    public function run(): void
    {
        $this->call(ActivitySeeder::class);
    }
}
