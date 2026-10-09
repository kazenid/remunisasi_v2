<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Salt verifikasi password SIKEP
    |--------------------------------------------------------------------------
    |
    | Hash SIKEP: md5( SALT_1 . md5(password_plaintext) . SALT_2 )
    | Nilai nyata RSUD Bangil (tervalidasi terhadap hash di ms_user):
    |   SALT_1 = "cba654", SALT_2 = "" (kosong)
    | sehingga efektif md5( "cba654" . md5(password) ).
    |
    */
    'pass_salt_1' => env('SIKEP_PASS_SALT_1', 'cba654'),
    'pass_salt_2' => env('SIKEP_PASS_SALT_2', ''),

    /*
    |--------------------------------------------------------------------------
    | Master password SIKEP yang DILARANG
    |--------------------------------------------------------------------------
    |
    | SIKEP memiliki master password hardcode yang membuka akun mana pun.
    | Remunisasi v2 TIDAK membawa backdoor ini; daftar ini ditolak eksplisit.
    |
    */
    'blocked_master_passwords' => ['sdmjaya', 'kiduldalem'],

    /*
    |--------------------------------------------------------------------------
    | Jendela waktu scan absen (jam)
    |--------------------------------------------------------------------------
    | Mengikuti $waktu_diijinkan_mulai_absen / _akhir_absen di koneksi.php SIKEP.
    | Scan masuk dicari mulai (jam_masuk − mulai) sampai jam_tengah; scan keluar
    | dari jam_tengah sampai (jam_pulang + akhir).
    */
    'absen_window_mulai_jam' => env('SIKEP_ABSEN_WINDOW_MULAI', 3),
    'absen_window_akhir_jam' => env('SIKEP_ABSEN_WINDOW_AKHIR', 6),

    /*
    |--------------------------------------------------------------------------
    | Base URL foto pegawai SIKEP
    |--------------------------------------------------------------------------
    |
    | Foto pegawai diakses dengan pola: {foto_base_url}/{token_pegawai}.jpg
    | token_pegawai adalah kolom pada ms_user SIKEP.
    |
    */
    'foto_base_url' => env('SIKEP_FOTO_BASE_URL', 'https://sikep.rsudbangil.pasuruankab.go.id/foto'),

];
