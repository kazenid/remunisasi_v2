<?php

namespace App\Services;

use App\Models\PersetujuanKinerja;
use App\Models\TugasHarian;
use App\Models\User;

class ApprovalService
{
    public function __construct(
        protected InsentifCalculatorService $calculator
    ) {}

    /**
     * Process approval, revision, rejection, or category/hour alteration.
     */
    public function process(
        TugasHarian $tugas,
        User $approver,
        string $tindakan, // 'setuju', 'perubahan_kategori', 'perubahan_jam', 'revisi', 'tolak'
        ?float $jamDisetujui = null,
        ?string $kategoriBaru = null,
        ?string $catatan = null
    ): TugasHarian {
        $level = $this->determineApproverLevel($approver, $tugas);

        $jamFinal = $jamDisetujui ?? ($tugas->jam_disetujui ?? $tugas->durasi_jam);

        // Record history
        PersetujuanKinerja::create([
            'tugas_harian_id' => $tugas->id,
            'approver_id' => $approver->id,
            'level' => $level,
            'tindakan' => $tindakan,
            'kategori_baru' => $kategoriBaru,
            'jam_disetujui' => in_array($tindakan, ['setuju', 'perubahan_kategori', 'perubahan_jam']) ? $jamFinal : null,
            'catatan' => $catatan,
        ]);

        if ($tindakan === 'tolak') {
            $tugas->update([
                'status' => 'ditolak',
                'alasan_tolak_revisi' => $catatan,
                'tgl_selesai_approval' => now(),
            ]);
        } elseif ($tindakan === 'revisi') {
            $tugas->update([
                'status' => 'revisi',
                'alasan_tolak_revisi' => $catatan,
            ]);
        } else {
            // Success approval flow
            if ($kategoriBaru && in_array($kategoriBaru, ['utama', 'tambahan'])) {
                $tugas->kategori = $kategoriBaru;
            }
            $tugas->jam_disetujui = $jamFinal;

            $nextStep = $this->getNextApprovalStep($level);
            $tugas->status = $nextStep['status'];
            $tugas->level_saat_ini = $nextStep['level'];

            if ($nextStep['level'] === 'selesai') {
                $tugas->tgl_selesai_approval = now();
            }

            $tugas->save();
        }

        // Recalculate employee's incentive
        if ($tugas->user && $tugas->periode) {
            $this->calculator->calculateForUser($tugas->user, $tugas->periode);
        }

        return $tugas->fresh();
    }

    protected function determineApproverLevel(User $approver, TugasHarian $tugas): string
    {
        // ─── Katimker flow (Perdir 186/2026) ────────────────────────────────
        // Kinerja Ketua Tim Kerja diverifikasi langsung oleh atasan langsungnya,
        // terlepas dari jabatan struktural approver tersebut.
        if ($this->isApprovingKatimker($approver, $tugas)) {
            return 'atasan_katimker';
        }

        if ($approver->isDirektur() || $approver->isAdmin()) {
            return 'direktur';
        }
        if ($approver->isWadir()) {
            return 'wadir';
        }
        if ($approver->isKabid()) {
            return 'kabid';
        }
        return 'katker';
    }

    /**
     * Level verifikasi pertama untuk pengajuan kinerja milik $user.
     *
     * Katimker yang sudah memiliki `atasan_langsung_id` masuk ke alur
     * `atasan_katimker` (satu tahap). Staf lain tetap memakai alur 4 tingkat.
     */
    public function initialLevelFor(User $user): string
    {
        return $this->isKatimkerFlow($user) ? 'atasan_katimker' : 'katker';
    }

    /**
     * Apakah $user mengikuti alur verifikasi khusus Katimker.
     */
    public function isKatimkerFlow(User $user): bool
    {
        return $user->isKatimker() && !empty($user->atasan_langsung_id);
    }

    /**
     * Apakah $approver adalah atasan langsung dari pemilik tugas Katimker.
     */
    protected function isApprovingKatimker(User $approver, TugasHarian $tugas): bool
    {
        $pemilik = $tugas->user;

        return $pemilik
            && $this->isKatimkerFlow($pemilik)
            && (int) $pemilik->atasan_langsung_id === (int) $approver->id;
    }

    protected function getNextApprovalStep(string $currentLevel): array
    {
        return match ($currentLevel) {
            'atasan_katimker' => ['status' => 'disetujui_atasan_katimker', 'level' => 'selesai'],
            'katker' => ['status' => 'disetujui_katker', 'level' => 'kabid'],
            'kabid' => ['status' => 'disetujui_kabid', 'level' => 'wadir'],
            'wadir' => ['status' => 'disetujui_wadir', 'level' => 'direktur'],
            'direktur' => ['status' => 'disetujui_direktur', 'level' => 'selesai'],
            default => ['status' => 'disetujui_katker', 'level' => 'kabid'],
        };
    }
}
