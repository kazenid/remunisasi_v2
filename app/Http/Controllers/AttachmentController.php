<?php

namespace App\Http\Controllers;

use App\Models\TaskAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Sajikan berkas bukti dari disk privat setelah pemeriksaan akses.
     * (Otorisasi granular diperketat pada Tugas 11 via AttachmentPolicy.)
     */
    public function show(Request $request, TaskAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);

        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->response($attachment->path, $attachment->nama_asli);
    }
}
