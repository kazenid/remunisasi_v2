<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Override target per individu. Mengalahkan target global (incentive_configs).
 * tahun/bulan null = berlaku umum untuk individu tsb.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('individual_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('katimker_sikep_user_id')->index();
            $table->smallInteger('tahun')->nullable();
            $table->tinyInteger('bulan')->nullable();
            $table->unsignedInteger('target_utama_menit')->nullable();
            $table->unsignedInteger('target_tambahan_menit')->nullable();
            $table->unsignedInteger('di_set_oleh')->nullable(); // sikep_user_id
            $table->timestamps();

            $table->unique(['katimker_sikep_user_id', 'tahun', 'bulan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('individual_targets');
    }
};
