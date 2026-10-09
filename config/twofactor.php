<?php

return [
    // Label issuer yang muncul di aplikasi authenticator.
    'issuer' => env('TWO_FACTOR_ISSUER', 'Remunisasi RSUD Bangil'),

    // Jumlah recovery code yang dibuat sekali enrollment.
    'recovery_code_count' => 8,

    // Toleransi window verifikasi TOTP (jumlah step 30 dtk ke tiap arah).
    // window=1 = step saat ini ±1 step (satu periode 30 dtk ke depan DAN ke
    // belakang; secara efektif sebuah kode bisa valid hingga ~90 dtk tergantung
    // posisi dalam periode). Trade-off clock-drift vs. jendela replay — 1
    // adalah nilai standar yang diterima.
    'window' => 1,
];
