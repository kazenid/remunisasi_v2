<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('periode_kinerja', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('tahun');
            $table->unsignedTinyInteger('bulan'); // 1 - 12
            $table->string('nama', 100); // e.g. "Oktober 2026"
            $table->unsignedTinyInteger('tgl_mulai')->default(1);
            $table->unsignedTinyInteger('tgl_cutoff')->default(10); // tgl cut-off bulan berikutnya
            $table->unsignedTinyInteger('tgl_cair')->default(15);
            $table->decimal('target_jam_utama', 6, 2)->default(150.00); // standar 150 jam
            $table->decimal('target_jam_tambahan', 6, 2)->default(15.00);
            $table->decimal('bobot_kehadiran', 5, 2)->default(40.00); // 40%
            $table->decimal('bobot_tugas_utama', 5, 2)->default(50.00); // 50%
            $table->decimal('bobot_tugas_tambahan', 5, 2)->default(10.00); // 10%
            $table->decimal('nominal_dasar', 15, 2)->default(10000000.00);
            $table->enum('status', ['draft', 'aktif', 'terkunci', 'selesai'])->default('draft');
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['tahun', 'bulan']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('periode_kinerja');
    }
};
