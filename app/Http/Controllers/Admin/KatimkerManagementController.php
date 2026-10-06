<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manajemen penugasan Ketua Tim Kerja (Katimker) & atasan langsungnya.
 *
 * Perdir RSUD Bangil 186/2026 Pasal 17: struktur Katimker tidak tersedia di
 * SIKEP sehingga penugasan dilakukan manual oleh admin/direktur.
 */
class KatimkerManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        $query = User::query()
            ->with('atasanLangsung:id,name,gelar_depan,gelar_belakang')
            ->where('is_katimker', true)
            ->orderBy('nama_unit')
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('nip', 'like', "%{$search}%")
                  ->orWhere('nama_unit', 'like', "%{$search}%");
            });
        }

        $katimkers = $query->paginate(20)->withQueryString();

        return Inertia::render('Admin/Katimker/Index', [
            'katimkers' => $katimkers,
            'filters' => $request->only(['search']),
            'unitOptions' => User::query()
                ->whereNotNull('id_unit')
                ->select('id_unit', 'nama_unit')
                ->distinct()
                ->orderBy('nama_unit')
                ->get()
                ->map(fn ($u) => ['id_unit' => $u->id_unit, 'nama_unit' => $u->nama_unit]),
        ]);
    }

    /**
     * Daftar kandidat pegawai (untuk pemilihan Katimker / atasan langsung).
     */
    public function candidates(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorizeManage($request);

        $query = User::query()
            ->where('is_active', true)
            ->select('id', 'name', 'gelar_depan', 'gelar_belakang', 'nip', 'role', 'id_unit', 'nama_unit', 'is_katimker')
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('nip', 'like', "%{$search}%");
            });
        }

        if ($request->filled('exclude')) {
            $query->where('id', '!=', $request->integer('exclude'));
        }

        return response()->json($query->limit(50)->get());
    }

    /**
     * Tandai/batalkan penanda pegawai sebagai Katimker beserta atasan langsungnya.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'is_katimker' => 'required|boolean',
            'atasan_langsung_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
                Rule::notIn([$user->id]),
            ],
        ]);

        // Katimker wajib memiliki atasan langsung agar alur verifikasi tidak macet.
        if ($validated['is_katimker'] && empty($validated['atasan_langsung_id'])) {
            return back()->withErrors([
                'atasan_langsung_id' => 'Atasan langsung wajib dipilih untuk seorang Katimker.',
            ]);
        }

        $user->update([
            'is_katimker' => $validated['is_katimker'],
            'atasan_langsung_id' => $validated['is_katimker']
                ? $validated['atasan_langsung_id']
                : null,
        ]);

        $label = $user->nama_lengkap;

        return back()->with(
            'success',
            $validated['is_katimker']
                ? "{$label} berhasil ditetapkan sebagai Katimker."
                : "Status Katimker untuk {$label} berhasil dibatalkan."
        );
    }

    /**
     * Penetapan massal: tandai beberapa pegawai sebagai Katimker dengan atasan yang sama.
     */
    public function bulkAssign(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => ['integer', Rule::exists('users', 'id')],
            'atasan_langsung_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id'),
                Rule::notIn($request->input('user_ids', [])),
            ],
        ]);

        $updated = User::whereIn('id', $validated['user_ids'])->update([
            'is_katimker' => true,
            'atasan_langsung_id' => $validated['atasan_langsung_id'],
        ]);

        return back()->with('success', "{$updated} pegawai berhasil ditetapkan sebagai Katimker.");
    }

    protected function authorizeManage(Request $request): void
    {
        if (!$request->user()->can('manageKatimker', User::class)) {
            abort(403, 'Hanya admin atau direktur yang dapat mengelola penugasan Katimker.');
        }
    }
}
