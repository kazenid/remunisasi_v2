<?php

namespace App\Http\Controllers;

use App\Models\PeriodeKinerja;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PeriodeKinerjaController extends Controller
{
    public function index(): Response
    {
        $periodes = PeriodeKinerja::orderBy('tahun', 'desc')
            ->orderBy('bulan', 'desc')
            ->paginate(12);

        return Inertia::render('Periode/Index', [
            'periodes' => $periodes,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tahun' => 'required|integer|min:2020|max:2099',
            'bulan' => 'required|integer|min:1|max:12',
            'nama' => 'required|string|max:100',
            'tgl_cutoff' => 'required|integer|min:1|max:31',
            'tgl_cair' => 'required|integer|min:1|max:31',
            'target_jam_utama' => 'required|numeric|min:1|max:500',
            'target_jam_tambahan' => 'required|numeric|min:0|max:100',
            'bobot_kehadiran' => 'required|numeric|min:0|max:100',
            'bobot_tugas_utama' => 'required|numeric|min:0|max:100',
            'bobot_tugas_tambahan' => 'required|numeric|min:0|max:100',
            'nominal_dasar' => 'required|numeric|min:0',
            'status' => 'required|in:draft,aktif,terkunci,selesai',
        ]);

        PeriodeKinerja::create($validated);

        return back()->with('success', 'Periode kinerja baru berhasil dibuat.');
    }

    public function update(Request $request, PeriodeKinerja $periode): RedirectResponse
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:100',
            'tgl_cutoff' => 'required|integer|min:1|max:31',
            'tgl_cair' => 'required|integer|min:1|max:31',
            'target_jam_utama' => 'required|numeric|min:1|max:500',
            'target_jam_tambahan' => 'required|numeric|min:0|max:100',
            'nominal_dasar' => 'required|numeric|min:0',
            'status' => 'required|in:draft,aktif,terkunci,selesai',
        ]);

        $periode->update($validated);

        return back()->with('success', 'Periode kinerja berhasil diperbarui.');
    }
}
