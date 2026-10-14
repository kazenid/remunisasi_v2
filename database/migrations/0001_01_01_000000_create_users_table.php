<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // User lokal = mirror ringan dari ms_user SIKEP.
        // Autentikasi dilakukan terhadap SIKEP; password TIDAK disimpan di sini.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sikep_user_id')->unique();
            $table->string('username')->nullable();
            $table->string('nama')->nullable();
            $table->unsignedInteger('no_absen')->nullable()->index();
            $table->string('token_pegawai')->nullable(); // untuk URL foto pegawai SIKEP

            // Peran lokal (sumber kebenaran, lepas dari jabatan SIKEP): admin|katimker|kabid.
            // is_admin = kolom turunan (role = admin <=> is_admin = true).
            $table->boolean('is_admin')->default(false);
            $table->string('role', 20)->nullable();
            $table->boolean('aktif')->default(true);

            // 2FA (TOTP) admin. Teks = ciphertext Crypt::encryptString.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->timestamp('last_sync_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
