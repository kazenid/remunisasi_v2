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
        Schema::table('users', function (Blueprint $table) {
            $table->string('nip', 50)->nullable()->unique()->after('id');
            $table->string('email')->nullable()->change();
            $table->string('gelar_depan', 20)->nullable()->after('name');
            $table->string('gelar_belakang', 50)->nullable()->after('gelar_depan');
            $table->unsignedInteger('id_unit')->nullable()->index()->after('gelar_belakang');
            $table->unsignedInteger('id_bidang')->nullable()->index()->after('id_unit');
            $table->string('nama_unit', 150)->nullable()->after('id_bidang');
            $table->string('nama_bidang', 150)->nullable()->after('nama_unit');
            $table->string('role', 50)->default('staf')->index()->after('nama_bidang'); // admin, direktur, kabid, kepala_unit, staf
            $table->boolean('is_active')->default(true)->after('role');
            $table->string('avatar')->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'nip',
                'gelar_depan',
                'gelar_belakang',
                'id_unit',
                'id_bidang',
                'nama_unit',
                'nama_bidang',
                'role',
                'is_active',
                'avatar',
            ]);
        });
    }
};
