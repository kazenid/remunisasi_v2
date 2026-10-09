<?php

namespace App\Http\Controllers;

use App\Models\ApproverMapping;
use App\Models\IndividualTarget;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class IndividualTargetController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Simpan override target individu (umum per individu: tahun & bulan null).
     * Admin boleh untuk siapa pun; Kabid hanya untuk Katimker binaannya.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'katimker_sikep_user_id' => 'required|integer',
            'target_utama_jam' => 'nullable|integer|min:0|max:1000',
            'target_tambahan_jam' => 'nullable|integer|min:0|max:1000',
        ]);

        $user = $request->user();
        $roles = $user->roles();
        $katimkerId = (int) $data['katimker_sikep_user_id'];

        // Otorisasi: Admin bebas; Kabid hanya binaannya.
        $boleh = $roles['is_admin']
            || ($roles['is_kabid'] && ApproverMapping::kabidFor($katimkerId) === $user->sikep_user_id);

        if (! $boleh) {
            throw ValidationException::withMessages([
                'katimker_sikep_user_id' => 'Anda tidak berwenang menetapkan target untuk Katimker ini.',
            ]);
        }

        $utama = ($data['target_utama_jam'] ?? null) !== null ? (int) $data['target_utama_jam'] * 60 : null;
        $tambahan = ($data['target_tambahan_jam'] ?? null) !== null ? (int) $data['target_tambahan_jam'] * 60 : null;

        $target = IndividualTarget::updateOrCreate(
            ['katimker_sikep_user_id' => $katimkerId, 'tahun' => null, 'bulan' => null],
            ['target_utama_menit' => $utama, 'target_tambahan_menit' => $tambahan, 'di_set_oleh' => $user->sikep_user_id],
        );

        $this->audit->record($user->sikep_user_id, 'target.update', $target, [
            'katimker_sikep_user_id' => $katimkerId,
            'target_utama_menit' => $utama,
            'target_tambahan_menit' => $tambahan,
        ]);

        return back()->with('success', 'Target individu disimpan.');
    }
}
