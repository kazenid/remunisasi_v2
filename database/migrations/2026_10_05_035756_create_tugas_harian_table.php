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
        Schema::create('tugas_harian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('periode_id')->constrained('periode_kinerja')->cascadeOnDelete();
            $table->date('tanggal')->index();
            $table->enum('kategori', ['utama', 'tambahan'])->default('utama')->index();
            $table->string('judul', 255);
            $table->text('deskripsi')->nullable();
            $table->time('jam_mulai')->nullable();
            $table->time('jam_selesai')->nullable();
            $table->decimal('durasi_jam', 5, 2)->default(0.00); // durasi jam diajukan
            $table->decimal('jam_disetujui', 5, 2)->nullable(); // durasi jam setelah approval
            $table->enum('status', [
                'draft',
                'diajukan',
                'disetujui_katker',
                'disetujui_kabid',
                'disetujui_wadir',
                'disetujui_direktur',
                'revisi',
                'ditolak'
            ])->default('draft')->index();
            $table->enum('level_saat_ini', [
                'katker',
                'kabid',
                'wadir',
                'direktur',
                'selesai'
            ])->default('katker')->index();
            $table->text('alasan_tolak_revisi')->nullable();
            $table->timestamp('tgl_pengajuan')->nullable();
            $table->timestamp('tgl_selesai_approval')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tanggal']);
            $table->index(['periode_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tugas_harian');
    }
};
