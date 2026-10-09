<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_report_id')->constrained('task_reports')->cascadeOnDelete();
            $table->enum('tipe', ['foto_selfie', 'dokumen']);
            $table->string('path');
            $table->string('nama_asli');
            $table->string('mime');
            $table->unsignedInteger('ukuran'); // byte
            $table->timestamp('diambil_pada')->nullable();
            $table->timestamps();

            $table->index(['task_report_id', 'tipe']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
    }
};
