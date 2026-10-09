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

            $table->unsignedInteger('katimker_sikep_user_id');          // pemilik
            $table->unsignedInteger('kabid_sikep_user_id')->nullable(); // snapshot penilai saat diajukan

            $table->date('tanggal');
            $table->enum('jenis', ['utama', 'tambahan']);
            $table->string('judul');
            $table->text('uraian')->nullable();

            $table->unsignedInteger('durasi_diajukan_menit');            // > 0
            $table->unsignedInteger('durasi_disetujui_menit')->nullable(); // 0..diajukan

            // String (bukan enum DB) agar penambahan status tahan lintas DB;
            // validasi nilai ditegakkan di aplikasi (TaskReport konstanta + FormRequest).
            $table->string('status', 20)->default('draf');

            $table->text('catatan_penilai')->nullable();
            $table->text('alasan_pengurangan')->nullable(); // wajib bila disetujui<diajukan atau 0

            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->float('akurasi')->nullable();

            $table->timestamp('diajukan_pada')->nullable();
            $table->timestamp('dinilai_pada')->nullable();

            $table->timestamps();

            $table->index(['katimker_sikep_user_id', 'status']);
            $table->index(['kabid_sikep_user_id', 'status']);
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reports');
    }
};
