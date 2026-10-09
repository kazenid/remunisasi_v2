<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Menyiapkan koneksi "sikep" sebagai SQLite in-memory untuk pengujian jalur yang
 * membaca ms_user (mis. UserController::tarik, app:set-admin). Baris dimasukkan
 * via query builder mentah (bukan Eloquent) agar tidak terkena guard read-only
 * SikepModel. Dipakai pada test yang perlu pegawai SIKEP nyata.
 */
trait SetsUpSikepConnection
{
    protected function setUpSikepSqlite(): void
    {
        config()->set('database.connections.sikep', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('sikep');
        $conn = DB::connection('sikep');

        $conn->getSchemaBuilder()->create('ms_user', function ($table) {
            $table->integer('id');
            $table->string('token_pegawai')->nullable();
            $table->string('username')->nullable();
            $table->integer('no_absen')->nullable();
            $table->string('no_pegawai')->nullable();
            $table->string('password')->nullable();
            $table->string('nama')->nullable();
            $table->string('gelar_depan')->nullable();
            $table->string('gelar_belakang')->nullable();
            $table->integer('aktif')->default(1);
        });
    }

    protected function buatPegawaiSikep(int $id, string $nama = 'PEGAWAI SIKEP', ?string $username = null): void
    {
        DB::connection('sikep')->table('ms_user')->insert([
            'id' => $id,
            'username' => $username ?? ('uji'.$id),
            'no_absen' => 900000 + $id,
            'no_pegawai' => (string) (800000 + $id),
            'nama' => $nama,
            'gelar_depan' => null,
            'gelar_belakang' => null,
            'aktif' => 1,
        ]);
    }
}
