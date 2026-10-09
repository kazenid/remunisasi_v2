<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApproverMapping;
use App\Models\IncentiveConfig;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengelolaan konfigurasi parameter insentif (Tahap 2).
 * Nilai disimpan sebagai konfigurasi; belum dipakai menghitung insentif.
 */
class IncentiveConfigController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage', ApproverMapping::class);

        $cfg = IncentiveConfig::global();

        return Inertia::render('Admin/KonfigurasiInsentif', [
            'config' => [
                'target_utama_jam' => $cfg->target_utama_menit !== null ? intdiv($cfg->target_utama_menit, 60) : null,
                'target_utama_menit_sisa' => $cfg->target_utama_menit !== null ? $cfg->target_utama_menit % 60 : null,
                'target_tambahan_jam' => $cfg->target_tambahan_menit !== null ? intdiv($cfg->target_tambahan_menit, 60) : null,
                'target_tambahan_menit_sisa' => $cfg->target_tambahan_menit !== null ? $cfg->target_tambahan_menit % 60 : null,
                'bobot_kehadiran' => $cfg->bobot_kehadiran,
                'bobot_utama' => $cfg->bobot_utama,
                'bobot_tambahan' => $cfg->bobot_tambahan,
                'nominal_dasar' => $cfg->nominal_dasar,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manage', ApproverMapping::class);

        $data = $request->validate([
            'target_utama_jam' => 'nullable|integer|min:0|max:1000',
            'target_utama_menit_sisa' => 'nullable|integer|min:0|max:59',
            'target_tambahan_jam' => 'nullable|integer|min:0|max:1000',
            'target_tambahan_menit_sisa' => 'nullable|integer|min:0|max:59',
            'bobot_kehadiran' => 'required|integer|min:0|max:100',
            'bobot_utama' => 'required|integer|min:0|max:100',
            'bobot_tambahan' => 'required|integer|min:0|max:100',
            'nominal_dasar' => 'nullable|integer|min:0',
        ]);

        if ($data['bobot_kehadiran'] + $data['bobot_utama'] + $data['bobot_tambahan'] !== 100) {
            throw ValidationException::withMessages([
                'bobot_kehadiran' => 'Total bobot (kehadiran + utama + tambahan) harus tepat 100%.',
            ]);
        }

        $targetUtama = $this->toMenit($data['target_utama_jam'] ?? null, $data['target_utama_menit_sisa'] ?? null);
        $targetTambahan = $this->toMenit($data['target_tambahan_jam'] ?? null, $data['target_tambahan_menit_sisa'] ?? null);

        $cfg = IncentiveConfig::global();
        $cfg->update([
            'target_utama_menit' => $targetUtama,
            'target_tambahan_menit' => $targetTambahan,
            'bobot_kehadiran' => $data['bobot_kehadiran'],
            'bobot_utama' => $data['bobot_utama'],
            'bobot_tambahan' => $data['bobot_tambahan'],
            'nominal_dasar' => $data['nominal_dasar'] ?? null,
            'diubah_oleh' => $request->user()->sikep_user_id,
        ]);

        $this->audit->record($request->user()->sikep_user_id, 'incentive_config.update', $cfg, [
            'bobot' => [$cfg->bobot_kehadiran, $cfg->bobot_utama, $cfg->bobot_tambahan],
            'target_utama_menit' => $cfg->target_utama_menit,
            'target_tambahan_menit' => $cfg->target_tambahan_menit,
            'nominal_dasar' => $cfg->nominal_dasar,
        ]);

        return back()->with('success', 'Konfigurasi insentif disimpan (belum dipakai untuk pembayaran).');
    }

    private function toMenit(?int $jam, ?int $menit): ?int
    {
        if ($jam === null && $menit === null) {
            return null;
        }

        return (int) $jam * 60 + (int) $menit;
    }
}
