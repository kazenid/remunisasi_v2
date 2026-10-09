<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApproverMapping;
use App\Models\AuditLog;
use App\Models\Sikep\SikepUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage', ApproverMapping::class); // admin-only (reuse policy)

        $filters = [
            'aktor' => $request->integer('aktor') ?: null,
            'tahun' => $request->integer('tahun') ?: null,
            'bulan' => $request->integer('bulan') ?: null,
        ];

        $query = AuditLog::query()->orderByDesc('id');

        if ($filters['aktor']) {
            $query->where('aktor_sikep_user_id', $filters['aktor']);
        }
        if ($filters['tahun']) {
            $query->whereYear('created_at', $filters['tahun']);
        }
        if ($filters['bulan']) {
            $query->whereMonth('created_at', $filters['bulan']);
        }

        $logs = $query->paginate(25)->withQueryString();

        // Resolusi nama aktor dari SIKEP.
        $aktorIds = collect($logs->items())->pluck('aktor_sikep_user_id')->filter()->unique();
        $nama = SikepUser::query()
            ->whereIn('id', $aktorIds)
            ->get()
            ->mapWithKeys(fn (SikepUser $u) => [$u->id => $u->nama_lengkap]);

        return Inertia::render('Admin/AuditLog', [
            'logs' => [
                'data' => collect($logs->items())->map(fn (AuditLog $l) => [
                    'id' => $l->id,
                    'aksi' => $l->aksi,
                    'subject_type' => class_basename((string) $l->subject_type),
                    'subject_id' => $l->subject_id,
                    'meta' => $l->meta,
                    'aktor_sikep_user_id' => $l->aktor_sikep_user_id,
                    'aktor_nama' => $l->aktor_sikep_user_id ? ($nama[$l->aktor_sikep_user_id] ?? ('#'.$l->aktor_sikep_user_id)) : 'Sistem',
                    'created_at' => $l->created_at?->toDateTimeString(),
                ]),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
            'filters' => $filters,
        ]);
    }
}
