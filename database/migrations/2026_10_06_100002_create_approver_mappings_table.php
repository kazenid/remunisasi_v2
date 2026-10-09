<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approver_mappings', function (Blueprint $table) {
            $table->id();
            // one-to-one: satu Katimker tepat satu Kabid.
            $table->unsignedInteger('katimker_sikep_user_id')->unique();
            $table->unsignedInteger('kabid_sikep_user_id')->index();
            $table->boolean('aktif')->default(true);
            $table->date('berlaku_mulai')->nullable();
            $table->unsignedInteger('diubah_oleh')->nullable(); // sikep_user_id admin
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approver_mappings');
    }
};
