<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mendukung penugasan tambahan oleh Kabid:
 * - dibuat_oleh_sikep_user_id: pembuat (null = Katimker sendiri; berisi id Kabid bila penugasan)
 * - jenis_terkunci: jenis tak dapat diubah Katimker
 * - status enum tambah 'menunggu_bukti'
 */
return new class extends Migration
{
    public function up(): void
    {
        // Status 'menunggu_bukti' kini sah karena kolom status bertipe string
        // (lihat migration create_task_reports); tak perlu ALTER enum.
        Schema::table('task_reports', function (Blueprint $table) {
            $table->unsignedInteger('dibuat_oleh_sikep_user_id')->nullable()->after('kabid_sikep_user_id');
            $table->boolean('jenis_terkunci')->default(false)->after('jenis');
        });
    }

    public function down(): void
    {
        Schema::table('task_reports', function (Blueprint $table) {
            $table->dropColumn(['dibuat_oleh_sikep_user_id', 'jenis_terkunci']);
        });
    }
};
