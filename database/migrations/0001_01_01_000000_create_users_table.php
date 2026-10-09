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
        // User lokal = mirror ringan dari ms_user SIKEP.
        // Autentikasi dilakukan terhadap SIKEP; password TIDAK disimpan di sini.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sikep_user_id')->unique();
            $table->string('username')->nullable();
            $table->string('nama')->nullable();
            $table->unsignedInteger('no_absen')->nullable()->index();
            $table->boolean('is_admin')->default(false);
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
