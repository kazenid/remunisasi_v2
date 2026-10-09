<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom `role` (nullable, domain admin|katimker|kabid) dan `aktif`
     * (boolean default true) ke tabel users. Role menjadi sumber kebenaran peran
     * (lepas dari jabatan SIKEP); `is_admin` dipertahankan sebagai kolom turunan.
     *
     * ->after('is_admin') hanya kosmetik (MySQL/MariaDB) dan diabaikan di SQLite.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->nullable()->after('is_admin');
            $table->boolean('aktif')->default(true)->after('role');
        });

        // Backfill idempotent — aman dijalankan pada data produksi.
        // 1) Admin lama -> role=admin, aktif=true (jaga invarian role=admin <=> is_admin=true).
        DB::table('users')->where('is_admin', true)->update([
            'role' => 'admin',
            'aktif' => true,
        ]);

        // 2) Non-admin lama -> HANYA role=null. `aktif` DIBIARKAN pada default true
        //    (Opsi A). Login non-admin lama tetap tertutup karena role=null
        //    (gerbang login + RoleResolver fail-closed pada role=null). Admin cukup
        //    menetapkan peran via menu Kepegawaian untuk membuka akses.
        DB::table('users')->where('is_admin', false)->update([
            'role' => null,
        ]);
    }

    /**
     * Hanya menghapus kolom `role` dan `aktif`; `is_admin` TIDAK disentuh.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'aktif']);
        });
    }
};
