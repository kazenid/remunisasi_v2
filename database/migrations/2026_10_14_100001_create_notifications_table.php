<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel notifikasi standar Laravel dengan presisi mikrodetik pada timestamp
     * agar urutan `latest()` deterministik untuk PK UUID (desain §3.1).
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at', 6)->nullable();
            $table->timestamp('created_at', 6)->nullable();
            $table->timestamp('updated_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
