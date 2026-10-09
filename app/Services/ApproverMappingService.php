<?php

namespace App\Services;

use App\Models\ApproverMapping;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Pengelolaan pemetaan approver Katimker → Kabid.
 * Kandidat Katimker/Kabid berasal dari user lokal ber-role (role + aktif),
 * ditetapkan manual oleh Admin (tidak diturunkan dari SIKEP). Kunci data tetap
 * `sikep_user_id`.
 */
class ApproverMappingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Daftar seluruh Katimker aktif beserta Kabid yang dipetakan (bila ada).
     *
     * @return Collection<int, array{sikep_user_id:int, nama:string, username:?string, no_absen:?int, kabid_sikep_user_id:?int, kabid_nama:?string, kabid_valid:bool}>
     */
    public function listKatimker(): Collection
    {
        $katimkerUsers = User::query()
            ->where('role', User::ROLE_KATIMKER)
            ->where('aktif', true)
            ->get()
            ->keyBy('sikep_user_id');

        $mappings = ApproverMapping::query()
            ->whereIn('katimker_sikep_user_id', $katimkerUsers->keys())
            ->where('aktif', true)
            ->get()
            ->keyBy('katimker_sikep_user_id');

        // Nama Kabid untuk ditampilkan — TIDAK difilter role/aktif agar nama
        // historis tetap tampil; validitasnya ditandai lewat `kabid_valid`.
        $kabidIds = $mappings->pluck('kabid_sikep_user_id')->unique()->filter()->values();
        $kabidUsers = User::query()->whereIn('sikep_user_id', $kabidIds)->get()->keyBy('sikep_user_id');

        return $katimkerUsers->map(function (User $u) use ($mappings, $kabidUsers) {
            $kabidId = $mappings->get($u->sikep_user_id)?->kabid_sikep_user_id;
            $kabidUser = $kabidId ? $kabidUsers->get($kabidId) : null;

            // Pemetaan valid bila kabid masih user ber-role 'kabid' DAN aktif.
            $kabidValid = $kabidUser !== null
                && $kabidUser->role === User::ROLE_KABID
                && $kabidUser->aktif === true;

            return [
                'sikep_user_id' => $u->sikep_user_id,
                'nama' => $u->nama,
                'username' => $u->username,
                'no_absen' => $u->no_absen,
                'kabid_sikep_user_id' => $kabidId,
                'kabid_nama' => $kabidUser?->nama,
                'kabid_valid' => $kabidValid,
            ];
        })->sortBy('nama')->values();
    }

    /**
     * Daftar seluruh Kabid aktif (untuk pilihan di antarmuka).
     *
     * @return Collection<int, array{sikep_user_id:int, nama:string}>
     */
    public function listKabid(): Collection
    {
        return User::query()
            ->where('role', User::ROLE_KABID)
            ->where('aktif', true)
            ->get()
            ->map(fn (User $u) => ['sikep_user_id' => $u->sikep_user_id, 'nama' => $u->nama])
            ->sortBy('nama')
            ->values();
    }

    /**
     * Katimker yang belum memiliki pemetaan aktif.
     *
     * @return Collection<int, int> daftar sikep_user_id
     */
    public function unassigned(): Collection
    {
        return $this->listKatimker()
            ->filter(fn ($row) => $row['kabid_sikep_user_id'] === null)
            ->pluck('sikep_user_id')
            ->values();
    }

    /**
     * Tetapkan/ubah Kabid untuk seorang Katimker (one-to-one). Mencatat audit.
     *
     * @throws InvalidArgumentException bila target bukan Kabid aktif, atau katimker tidak valid.
     */
    public function assign(int $katimkerSikepUserId, int $kabidSikepUserId, ?int $aktorSikepUserId = null): ApproverMapping
    {
        if (! $this->isKatimker($katimkerSikepUserId)) {
            throw new InvalidArgumentException('Pengguna bukan Katimker aktif.');
        }

        if (! $this->isKabid($kabidSikepUserId)) {
            throw new InvalidArgumentException('Target penilai bukan Kabid aktif.');
        }

        $existing = ApproverMapping::query()
            ->where('katimker_sikep_user_id', $katimkerSikepUserId)
            ->first();

        $old = $existing?->kabid_sikep_user_id;

        $mapping = ApproverMapping::updateOrCreate(
            ['katimker_sikep_user_id' => $katimkerSikepUserId],
            [
                'kabid_sikep_user_id' => $kabidSikepUserId,
                'aktif' => true,
                'diubah_oleh' => $aktorSikepUserId,
            ],
        );

        $this->audit->record($aktorSikepUserId, 'mapping.update', $mapping, [
            'katimker_sikep_user_id' => $katimkerSikepUserId,
            'kabid' => ['dari' => $old, 'ke' => $kabidSikepUserId],
        ]);

        return $mapping;
    }

    public function isKatimker(int $sikepUserId): bool
    {
        return User::query()
            ->where('sikep_user_id', $sikepUserId)
            ->where('role', User::ROLE_KATIMKER)
            ->where('aktif', true)
            ->exists();
    }

    public function isKabid(int $sikepUserId): bool
    {
        return User::query()
            ->where('sikep_user_id', $sikepUserId)
            ->where('role', User::ROLE_KABID)
            ->where('aktif', true)
            ->exists();
    }
}
