<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil perhitungan insentif yang DIFINALKAN per pegawai per periode.
 * Disimpan hanya saat Admin menekan "Finalkan"; selama simulasi tidak ditulis.
 * Re-finalisasi menimpa baris periode yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incentive_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('katimker_sikep_user_id')->index();
            $table->smallInteger('tahun');
            $table->tinyInteger('bulan');

            // Komponen pencapaian (persen).
            $table->decimal('persen_kehadiran', 6, 2);
            $table->decimal('persen_utama', 6, 2);
            $table->decimal('persen_tambahan', 6, 2);
            $table->decimal('persen_total', 6, 2);

            // Nominal (rupiah penuh).
            $table->unsignedBigInteger('nominal_plafon');
            $table->unsignedBigInteger('nominal_insentif');

            // Rekap menit & target saat finalisasi (snapshot).
            $table->unsignedInteger('utama_menit');
            $table->unsignedInteger('tambahan_menit');
            $table->unsignedInteger('target_utama_menit')->nullable();
            $table->unsignedInteger('target_tambahan_menit')->nullable();

            // Bobot yang dipakai (snapshot).
            $table->unsignedTinyInteger('bobot_kehadiran');
            $table->unsignedTinyInteger('bobot_utama');
            $table->unsignedTinyInteger('bobot_tambahan');

            $table->unsignedInteger('difinalkan_oleh')->nullable(); // sikep_user_id
            $table->timestamp('difinalkan_pada')->nullable();
            $table->timestamps();

            $table->unique(['katimker_sikep_user_id', 'tahun', 'bulan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incentive_results');
    }
};
