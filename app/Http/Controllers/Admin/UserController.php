<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApproverMapping;
use App\Models\Sikep\SikepUser;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage', ApproverMapping::class); // admin-only

        $q = trim((string) $request->query('q', ''));

        return Inertia::render('Admin/Pengguna', [
            'users' => $this->daftarUserLokal(),
            'hasilCari' => $this->cariPegawai($q),
            'q' => $q,
            'roleOptions' => $this->roleOptions(),
        ]);
    }

    /**
     * Opsi role untuk dropdown penetapan peran.
     *
     * @return array<int, array{label:string, value:string}>
     */
    private function roleOptions(): array
    {
        return [
            ['label' => 'Admin', 'value' => User::ROLE_ADMIN],
            ['label' => 'Katimker', 'value' => User::ROLE_KATIMKER],
            ['label' => 'Kabid', 'value' => User::ROLE_KABID],
        ];
    }

    /**
     * Daftar user lokal beserta role & status aktif (nilai kolom apa adanya).
     */
    private function daftarUserLokal(): array
    {
        return User::query()->orderBy('nama')->get()->map(fn (User $u) => [
            'id' => $u->id,
            'sikep_user_id' => $u->sikep_user_id,
            'nama' => $u->nama,
            'username' => $u->username,
            'no_absen' => $u->no_absen,
            'role' => $u->role,
            'aktif' => $u->aktif,
            'last_sync_at' => $u->last_sync_at?->toDateTimeString(),
        ])->all();
    }

    /**
     * Cari pegawai SIKEP aktif (minimal 3 karakter). Tandai yang sudah ditarik
     * (ada record users lokal) beserta role lokalnya bila ada.
     *
     * @return array<int, array{sikep_user_id:int, nama:string, username:?string, no_absen:?int, sudah_ditarik:bool, role:?string}>
     */
    private function cariPegawai(string $q): array
    {
        if (mb_strlen($q) < 3) {
            return [];
        }

        $hasil = SikepUser::query()
            ->where('aktif', 1)
            ->where(function ($w) use ($q) {
                $w->where('nama', 'like', "%{$q}%")
                    ->orWhere('username', 'like', "%{$q}%")
                    ->orWhere('no_absen', 'like', "%{$q}%");
            })
            ->limit(25)
            ->get();

        $lokal = User::query()
            ->whereIn('sikep_user_id', $hasil->pluck('id'))
            ->get(['sikep_user_id', 'role'])
            ->keyBy('sikep_user_id');

        return $hasil->map(function (SikepUser $u) use ($lokal) {
            $record = $lokal->get($u->id);

            return [
                'sikep_user_id' => $u->id,
                'nama' => $u->nama_lengkap,
                'username' => $u->username,
                'no_absen' => $u->no_absen,
                'sudah_ditarik' => $record !== null,
                'role' => $record?->role,
            ];
        })->all();
    }

    /**
     * Tarik pegawai SIKEP ke aplikasi dan tetapkan role. Hanya untuk pembuatan
     * record baru; record yang sudah ada dikelola di daftar user lokal.
     */
    public function tarik(Request $request): RedirectResponse
    {
        $this->authorize('manage', ApproverMapping::class);

        $data = $request->validate([
            'sikep_user_id' => 'required|integer',
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        $aktor = $request->user();

        // Guard "sudah ditarik": tarik() murni untuk record baru.
        if (User::where('sikep_user_id', $data['sikep_user_id'])->exists()) {
            throw ValidationException::withMessages([
                'sikep_user_id' => 'Pegawai sudah ditarik. Kelola peran/status di daftar user lokal.',
            ]);
        }

        $sikep = SikepUser::query()->where('id', $data['sikep_user_id'])->where('aktif', 1)->first();

        if (! $sikep) {
            throw ValidationException::withMessages([
                'sikep_user_id' => 'Pegawai SIKEP aktif tidak ditemukan.',
            ]);
        }

        $user = User::syncFromSikep($sikep);
        $user->setRole($data['role']);
        $user->aktif = true;
        $user->save();

        $this->audit->record($aktor->sikep_user_id, 'pengguna.tarik', $user, [
            'target_sikep_user_id' => $user->sikep_user_id,
            'role' => ['dari' => null, 'ke' => $data['role']],
            'aktif' => true,
        ]);

        return back()->with('success', 'Pegawai ditarik dan peran ditetapkan.');
    }

    /**
     * Ubah role user lokal (anti-lockout). TIDAK menyentuh status aktif.
     */
    public function ubahRole(User $user, Request $request): RedirectResponse
    {
        $this->authorize('manage', ApproverMapping::class);

        $data = $request->validate([
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        $aktor = $request->user();

        // Anti-lockout: tak boleh menurunkan peran admin diri sendiri.
        if ($user->id === $aktor->id && $data['role'] !== User::ROLE_ADMIN) {
            throw ValidationException::withMessages([
                'role' => 'Anda tidak dapat menurunkan peran admin diri sendiri.',
            ]);
        }

        // Anti-lockout global: tak boleh menyisakan nol admin aktif.
        if ($this->akanMenyisakanNolAdmin($user, roleBaru: $data['role'])) {
            throw ValidationException::withMessages([
                'role' => 'Minimal satu administrator aktif harus tersisa.',
            ]);
        }

        $lama = $user->role;
        $user->setRole($data['role']);
        $user->save();

        $this->audit->record($aktor->sikep_user_id, 'pengguna.role', $user, [
            'target_sikep_user_id' => $user->sikep_user_id,
            'role' => ['dari' => $lama, 'ke' => $user->role],
        ]);

        return back()->with('success', 'Peran pengguna diperbarui.');
    }

    /**
     * Aktifkan/nonaktifkan user lokal (anti-lockout). Record TIDAK dihapus.
     */
    public function toggleAktif(User $user, Request $request): RedirectResponse
    {
        $this->authorize('manage', ApproverMapping::class);

        $data = $request->validate([
            'aktif' => 'required|boolean',
        ]);

        $aktor = $request->user();

        // Anti-lockout: tak boleh menonaktifkan akun sendiri.
        if ($user->id === $aktor->id && $data['aktif'] === false) {
            throw ValidationException::withMessages([
                'aktif' => 'Anda tidak dapat menonaktifkan akun sendiri.',
            ]);
        }

        // Anti-lockout global: menonaktifkan admin tak boleh menyisakan nol admin aktif.
        if ($data['aktif'] === false && $this->akanMenyisakanNolAdmin($user, aktifBaru: false)) {
            throw ValidationException::withMessages([
                'aktif' => 'Minimal satu administrator aktif harus tersisa.',
            ]);
        }

        $lama = $user->aktif;
        $user->aktif = $data['aktif'];
        $user->save();

        $this->audit->record($aktor->sikep_user_id, 'pengguna.aktif', $user, [
            'target_sikep_user_id' => $user->sikep_user_id,
            'aktif' => ['dari' => $lama, 'ke' => $user->aktif],
        ]);

        return back()->with('success', 'Status aktif pengguna diperbarui.');
    }

    /**
     * Apakah perubahan pada $user (ganti role / nonaktifkan) akan menyisakan nol
     * admin aktif di sistem. Menghitung admin aktif LAIN; bila nol dan $user
     * sedang berhenti menjadi admin aktif, perubahan akan mengunci sistem.
     */
    private function akanMenyisakanNolAdmin(User $user, ?string $roleBaru = null, ?bool $aktifBaru = null): bool
    {
        $adminAktifLain = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('aktif', true)
            ->where('id', '!=', $user->id)
            ->count();

        if ($adminAktifLain > 0) {
            return false;
        }

        // Tidak ada admin aktif lain. Cek apakah $user tetap admin aktif setelah perubahan.
        $tetapAdmin = $roleBaru !== null ? $roleBaru === User::ROLE_ADMIN : $user->role === User::ROLE_ADMIN;
        $tetapAktif = $aktifBaru !== null ? $aktifBaru : $user->aktif;

        // Hanya memblokir bila $user ini SEBELUMNYA admin aktif (satu-satunya).
        $sebelumnyaAdminAktif = $user->role === User::ROLE_ADMIN && $user->aktif === true;

        return $sebelumnyaAdminAktif && ! ($tetapAdmin && $tetapAktif);
    }
}
