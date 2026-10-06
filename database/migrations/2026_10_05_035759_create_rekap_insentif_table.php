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
        Schema::create('rekap_insentif', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('periode_id')->constrained('periode_kinerja')->cascadeOnDelete();
            $table->decimal('persentase_kehadiran', 5, 2)->default(100.00);
            $table->decimal('jam_utama_disetujui', 6, 2)->default(0.00);
            $table->decimal('target_jam_utama', 6, 2)->default(150.00);
            $table->decimal('capaian_jam_utama', 5, 2)->default(0.00);
            $table->decimal('jam_tambahan_disetujui', 6, 2)->default(0.00);
            $table->decimal('target_jam_tambahan', 6, 2)->default(15.00);
            $table->decimal('capaian_jam_tambahan', 5, 2)->default(0.00);
            $table->decimal('skor_kehadiran', 5, 2)->default(0.00); // persentase_kehadiran * 40%
            $table->decimal('skor_utama', 5, 2)->default(0.00); // capaian_jam_utama * 50%
            $table->decimal('skor_tambahan', 5, 2)->default(0.00); // capaian_jam_tambahan * 10%
            $table->decimal('total_persen_kinerja', 5, 2)->default(0.00); // max 100%
            $table->decimal('nominal_dasar', 15, 2)->default(0.00);
            $table->decimal('nominal_insentif', 15, 2)->default(0.00);
            $table->enum('status', ['draft', 'dikunci', 'cair'])->default('draft');
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'periode_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rekap_insentif');
    }
};
