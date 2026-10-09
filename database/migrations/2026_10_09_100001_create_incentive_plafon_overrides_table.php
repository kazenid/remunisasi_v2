<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Override plafon insentif per pegawai per periode. Mengalahkan nominal dasar
 * global (incentive_configs.nominal_dasar). Satu baris per Katimker per periode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incentive_plafon_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('katimker_sikep_user_id')->index();
            $table->smallInteger('tahun');
            $table->tinyInteger('bulan');
            $table->unsignedBigInteger('nominal'); // rupiah penuh
            $table->unsignedInteger('di_set_oleh')->nullable(); // sikep_user_id
            $table->timestamps();

            $table->unique(['katimker_sikep_user_id', 'tahun', 'bulan'], 'plafon_override_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incentive_plafon_overrides');
    }
};
