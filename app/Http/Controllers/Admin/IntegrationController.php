<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApproverMapping;
use App\Models\Sikep\SikepUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage', ApproverMapping::class);

        $status = 'down';
        $jumlahPegawai = null;
        $pesan = null;

        try {
            $jumlahPegawai = SikepUser::query()->count();
            $status = 'up';
        } catch (\Throwable $e) {
            report($e);
            $pesan = 'Tidak dapat terhubung ke basis data SIKEP.';
        }

        return Inertia::render('Admin/Integrasi', [
            'integrasi' => [
                'status' => $status,
                'jumlah_pegawai' => $jumlahPegawai,
                'pesan' => $pesan,
                // Info koneksi tanpa membocorkan kredensial.
                'host' => config('database.connections.sikep.host'),
                'port' => config('database.connections.sikep.port'),
                'database' => config('database.connections.sikep.database'),
                'dicek_pada' => now()->toDateTimeString(),
            ],
        ]);
    }
}
