<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ApproverMapping;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Master kegiatan: daftar nama kegiatan baku untuk input tugas. */
class ActivityController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('manage', ApproverMapping::class);

        return Inertia::render('Admin/Kegiatan', [
            'kegiatan' => Activity::query()
                ->withCount('tugas')
                ->orderBy('nama')
                ->get()
                ->map(fn (Activity $a) => [
                    'id' => $a->id,
                    'nama' => $a->nama,
                    'aktif' => $a->aktif,
                    'tugas_count' => $a->tugas_count,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage', ApproverMapping::class);

        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255', Rule::unique('activities', 'nama')],
        ], $this->pesan());

        $kegiatan = Activity::create(['nama' => trim($data['nama']), 'aktif' => true]);

        $this->audit->record($request->user()->sikep_user_id, 'activity.create', $kegiatan, ['nama' => $kegiatan->nama]);

        return back()->with('success', 'Kegiatan ditambahkan.');
    }

    public function update(Request $request, Activity $kegiatan): RedirectResponse
    {
        $this->authorize('manage', ApproverMapping::class);

        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255', Rule::unique('activities', 'nama')->ignore($kegiatan->id)],
            'aktif' => ['required', 'boolean'],
        ], $this->pesan());

        $lama = ['nama' => $kegiatan->nama, 'aktif' => $kegiatan->aktif];
        $kegiatan->update(['nama' => trim($data['nama']), 'aktif' => (bool) $data['aktif']]);

        $this->audit->record($request->user()->sikep_user_id, 'activity.update', $kegiatan, [
            'dari' => $lama,
            'ke' => ['nama' => $kegiatan->nama, 'aktif' => $kegiatan->aktif],
        ]);

        return back()->with('success', 'Kegiatan diperbarui.');
    }

    public function destroy(Request $request, Activity $kegiatan): RedirectResponse
    {
        $this->authorize('manage', ApproverMapping::class);

        if ($kegiatan->tugas()->exists()) {
            return back()->with('error', 'Kegiatan sudah dipakai pada tugas, nonaktifkan saja.');
        }

        $nama = $kegiatan->nama;
        $kegiatan->delete();

        $this->audit->record($request->user()->sikep_user_id, 'activity.delete', $kegiatan, ['nama' => $nama]);

        return back()->with('success', 'Kegiatan dihapus.');
    }

    /** @return array<string,string> */
    private function pesan(): array
    {
        return [
            'nama.required' => 'Nama kegiatan wajib diisi.',
            'nama.unique' => 'Nama kegiatan sudah ada.',
        ];
    }
}
