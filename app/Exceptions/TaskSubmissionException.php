<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dilempar saat pengajuan tugas tidak memenuhi syarat
 * (durasi, bukti selfie, atau pemetaan approver belum ada).
 */
class TaskSubmissionException extends RuntimeException {}
