<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katimker (Ketua Tim Kerja) approval system.
 *
 * Scope (Perdir RSUD Bangil 186/2026):
 *  - Pegawai yang ditandai `is_katimker` mengajukan kinerja harian yang diverifikasi
 *    langsung oleh `atasan_langsung_id` (satu tingkat di atasnya).
 *  - Alur 4 tingkat (katker -> kabid -> wadir -> direktur) untuk staf biasa TIDAK berubah.
 *
 * Migrasi ini bersifat additive (kolom nullable + perluasan enum) sehingga aman
 * dijalankan tanpa downtime dan mudah di-rollback.
 */
return new class extends Migration
{
    private const STATUS_VALUES = [
        'draft',
        'diajukan',
        'disetujui_katker',
        'disetujui_kabid',
        'disetujui_wadir',
        'disetujui_direktur',
        'disetujui_atasan_katimker',
        'revisi',
        'ditolak',
    ];

    private const LEVEL_VALUES = [
        'katker',
        'kabid',
        'wadir',
        'direktur',
        'atasan_katimker',
        'selesai',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('atasan_langsung_id')->nullable()->after('role');
            $table->boolean('is_katimker')->default(false)->after('atasan_langsung_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('atasan_langsung_id');
            $table->index('is_katimker');
            $table->foreign('atasan_langsung_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::table('tugas_harian', function (Blueprint $table) {
            $table->enum('status', self::STATUS_VALUES)->default('draft')->change();
            $table->enum('level_saat_ini', self::LEVEL_VALUES)->default('katker')->change();
        });

        Schema::table('persetujuan_kinerja', function (Blueprint $table) {
            $table->enum('level', ['katker', 'kabid', 'wadir', 'direktur', 'atasan_katimker'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('persetujuan_kinerja', function (Blueprint $table) {
            $table->enum('level', ['katker', 'kabid', 'wadir', 'direktur'])->change();
        });

        Schema::table('tugas_harian', function (Blueprint $table) {
            $table->enum('status', [
                'draft',
                'diajukan',
                'disetujui_katker',
                'disetujui_kabid',
                'disetujui_wadir',
                'disetujui_direktur',
                'revisi',
                'ditolak',
            ])->default('draft')->change();

            $table->enum('level_saat_ini', [
                'katker',
                'kabid',
                'wadir',
                'direktur',
                'selesai',
            ])->default('katker')->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['atasan_langsung_id']);
            $table->dropIndex(['atasan_langsung_id']);
            $table->dropIndex(['is_katimker']);
            $table->dropColumn(['atasan_langsung_id', 'is_katimker']);
        });
    }
};
