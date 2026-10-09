<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dilempar saat penilaian Kabid melanggar aturan
 * (batas jam disetujui, alasan/catatan wajib, atau status tugas tidak valid).
 */
class ReviewException extends RuntimeException {}
