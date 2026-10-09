<?php

namespace App\Console\Commands;

use App\Models\Sikep\SikepUser;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Menandai (atau mencabut) peran Admin lokal untuk seorang pengguna.
 * Pengguna dicari dari SIKEP via username / no_absen / sikep_user_id,
 * lalu user lokal dibuat/di-sync bila belum ada.
 *
 * Contoh:
 *   php artisan app:set-admin 1290040
 *   php artisan app:set-admin 1290040 --off
 */
class SetAdmin extends Command
{
    protected $signature = 'app:set-admin {login : username, no_absen, atau sikep_user_id} {--off : cabut status admin}';

    protected $description = 'Tandai/cabut peran Admin lokal untuk pengguna SIKEP';

    public function handle(): int
    {
        $login = (string) $this->argument('login');

        $sikep = SikepUser::findByLogin($login)
            ?? SikepUser::query()->where('id', $login)->where('aktif', 1)->first();

        if (! $sikep) {
            $this->error("Pengguna SIKEP aktif tidak ditemukan untuk '{$login}'.");

            return self::FAILURE;
        }

        $user = User::syncFromSikep($sikep);

        if ($this->option('off')) {
            // Cabut status admin: role=null, is_admin=false. `aktif` TIDAK diubah.
            $user->setRole(null);
        } else {
            // Jadikan admin: role=admin, is_admin=true, dan pastikan akun aktif.
            $user->setRole(User::ROLE_ADMIN);
            $user->aktif = true;
        }
        $user->save();

        $status = $this->option('off') ? 'DICABUT dari admin' : 'DIJADIKAN admin';
        $this->info("{$sikep->nama_lengkap} (sikep_user_id={$sikep->id}) {$status}.");

        return self::SUCCESS;
    }
}
