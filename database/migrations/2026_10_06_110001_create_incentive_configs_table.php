<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konfigurasi parameter insentif (Tahap 2). Disimpan sebagai konfigurasi;
 * BELUM dipakai untuk menghitung/membayar insentif pada tahap ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incentive_configs', function (Blueprint $table) {
            $table->id();

            // Lingkup: null,null = default global; atau spesifik per periode.
            $table->smallInteger('tahun')->nullable();
            $table->tinyInteger('bulan')->nullable();

            // Target jam (disimpan sebagai menit, konsisten dgn task_reports).
            $table->unsignedInteger('target_utama_menit')->nullable();
            $table->unsignedInteger('target_tambahan_menit')->nullable();

            // Bobot komponen (persen, total diharapkan 100).
            $table->unsignedTinyInteger('bobot_kehadiran')->default(40);
            $table->unsignedTinyInteger('bobot_utama')->default(50);
            $table->unsignedTinyInteger('bobot_tambahan')->default(10);

            // Nominal insentif dasar (rupiah), nullable sampai disepakati.
            $table->unsignedBigInteger('nominal_dasar')->nullable();

            $table->boolean('aktif')->default(true);
            $table->unsignedInteger('diubah_oleh')->nullable();
            $table->timestamps();

            $table->unique(['tahun', 'bulan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incentive_configs');
    }
};
