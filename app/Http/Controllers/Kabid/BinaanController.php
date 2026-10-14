<?php

namespace App\Http\Controllers\Kabid;

use App\Http\Controllers\Controller;
use App\Models\ApproverMapping;
use App\Models\Sikep\SikepUser;
use App\Models\TaskReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Detail kinerja satu Katimker binaan: seluruh tugasnya yang menjadi tanggung jawab Kabid. */
class BinaanController extends Controller
{
    public function tugas(Request $request, int $katimker): Response
    {
        $kabidId = $request->user()->sikep_user_id;

        $binaan = ApproverMapping::query()
            ->where('kabid_sikep_user_id', $kabidId)
            ->where('katimker_sikep_user_id', $katimker)
            ->where('aktif', true)
            ->exists();
        abort_unless($binaan, 403);

        $tugas = TaskReport::query()
            ->untukKabid($kabidId)
            ->milik($katimker)
            ->withCount('attachments')
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TaskReport $t) => [
                'id' => $t->id,
                'tanggal' => $t->tanggal->toDateString(),
                'tahun' => (int) $t->tanggal->year,
                'bulan' => (int) $t->tanggal->month,
                'judul' => $t->judul,
                'uraian' => $t->uraian,
                'jenis' => $t->jenis,
                'status' => $t->status,
                'durasi_diajukan_menit' => $t->durasi_diajukan_menit,
                'durasi_disetujui_menit' => $t->durasi_disetujui_menit,
                'attachments_count' => $t->attachments_count,
            ]);

        return Inertia::render('Kabid/BinaanTugas', [
            'katimker' => [
                'sikep_user_id' => $katimker,
                'nama' => SikepUser::find($katimker)?->nama_lengkap ?? ('#'.$katimker),
            ],
            'tugas' => $tugas,
        ]);
    }
}
