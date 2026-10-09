<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('tahun');
            $table->tinyInteger('bulan'); // 1..12
            $table->date('cut_off')->nullable();      // default tgl 10
            $table->date('target_cair')->nullable();  // default tgl 15
            $table->enum('status', ['terbuka', 'terkunci'])->default('terbuka');
            $table->timestamps();

            $table->unique(['tahun', 'bulan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periods');
    }
};
