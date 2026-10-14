<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->nullable()->constrained('periods')->nullOnDelete();

            $table->unsignedInteger('katimker_sikep_user_id');                // pemilik
            $table->unsignedInteger('kabid_sikep_user_id')->nullable();       // snapshot penilai saat disimpan
            $table->unsignedInteger('dibuat_oleh_sikep_user_id')->nullable(); // null = Katimker sendiri; Kabid bila penugasan

            $table->date('tanggal');
            $table->enum('jenis', ['utama', 'tambahan']);
            $table->boolean('jenis_terkunci')->default(false); // penugasan Kabid: jenis tak dapat diubah Katimker

            // Kegiatan baku (master). `judul` menyimpan snapshot nama kegiatan saat dicatat,
            // sehingga riwayat tetap utuh walau master diubah/dihapus.
            $table->foreignId('kegiatan_id')->nullable()->constrained('activities')->nullOnDelete();
            $table->string('judul');
            $table->text('uraian')->nullable();

            $table->unsignedInteger('durasi_diajukan_menit');              // > 0 (0 hanya pada penugasan menunggu bukti)
            $table->unsignedInteger('durasi_disetujui_menit')->nullable(); // 0..diajukan

            // String (bukan enum DB) agar penambahan status tahan lintas DB; nilai ditegakkan
            // di aplikasi: menunggu_bukti | diajukan (proses penilaian) | revisi | disetujui | ditolak.
            $table->string('status', 20)->default('diajukan');

            $table->text('catatan_penilai')->nullable();
            $table->text('alasan_pengurangan')->nullable(); // wajib bila disetujui < diajukan

            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->float('akurasi')->nullable();

            $table->timestamp('diajukan_pada')->nullable();
            $table->timestamp('dinilai_pada')->nullable();

            $table->timestamps();

            // Rekap/dashboard: filter per pemilik/penilai + status atau rentang tanggal.
            $table->index(['katimker_sikep_user_id', 'status']);
            $table->index(['katimker_sikep_user_id', 'tanggal']);
            $table->index(['kabid_sikep_user_id', 'status']);
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reports');
    }
};
