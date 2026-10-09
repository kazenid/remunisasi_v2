<?php

namespace Tests\Unit;

use App\Models\Sikep\SikepUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Memastikan model SIKEP memblokir seluruh operasi tulis.
 * Tidak membutuhkan koneksi SIKEP hidup: guard melempar sebelum menyentuh DB.
 */
class SikepReadOnlyTest extends TestCase
{
    public function test_save_dilarang(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('read-only');

        (new SikepUser)->save();
    }

    public function test_delete_dilarang(): void
    {
        $this->expectException(RuntimeException::class);

        (new SikepUser)->delete();
    }

    public function test_update_dilarang(): void
    {
        $this->expectException(RuntimeException::class);

        (new SikepUser)->update(['nama' => 'X']);
    }

    public function test_force_delete_dilarang(): void
    {
        $this->expectException(RuntimeException::class);

        (new SikepUser)->forceDelete();
    }

    public function test_koneksi_dan_timestamps(): void
    {
        $model = new SikepUser;

        $this->assertSame('sikep', $model->getConnectionName());
        $this->assertFalse($model->usesTimestamps());
    }

    public function test_nama_lengkap_merapikan_gelar(): void
    {
        $model = new SikepUser;
        $model->gelar_depan = 'dr';
        $model->nama = 'BUDI SANTOSO';
        $model->gelar_belakang = 'Sp.PD';

        $this->assertSame('dr BUDI SANTOSO Sp.PD', $model->nama_lengkap);

        $model2 = new SikepUser;
        $model2->gelar_depan = null;
        $model2->nama = 'SITI AMINAH';
        $model2->gelar_belakang = '';

        $this->assertSame('SITI AMINAH', $model2->nama_lengkap);
    }
}
