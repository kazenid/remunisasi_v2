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
        Schema::create('persetujuan_kinerja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tugas_harian_id')->constrained('tugas_harian')->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->cascadeOnDelete();
            $table->enum('level', ['katker', 'kabid', 'wadir', 'direktur']);
            $table->enum('tindakan', ['setuju', 'perubahan_kategori', 'perubahan_jam', 'revisi', 'tolak']);
            $table->enum('kategori_baru', ['utama', 'tambahan'])->nullable();
            $table->decimal('jam_disetujui', 5, 2)->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index(['tugas_harian_id', 'level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('persetujuan_kinerja');
    }
};
