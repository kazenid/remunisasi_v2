<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApproverMapping;
use App\Services\ApproverMappingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApproverMappingController extends Controller
{
    public function __construct(private readonly ApproverMappingService $service) {}

    public function index(): Response
    {
        $this->authorize('manage', ApproverMapping::class);

        return Inertia::render('Admin/ApproverMapping', [
            'katimker' => $this->service->listKatimker(),
            'kabidOptions' => $this->service->listKabid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage', ApproverMapping::class);

        $data = $request->validate([
            'katimker_sikep_user_id' => 'required|integer',
            'kabid_sikep_user_id' => 'required|integer',
        ]);

        try {
            $this->service->assign(
                $data['katimker_sikep_user_id'],
                $data['kabid_sikep_user_id'],
                $request->user()->sikep_user_id,
            );
        } catch (\InvalidArgumentException $e) {
            // Petakan pesan ke field yang relevan (Katimker vs Kabid).
            $field = str_contains($e->getMessage(), 'Katimker')
                ? 'katimker_sikep_user_id'
                : 'kabid_sikep_user_id';

            throw ValidationException::withMessages([
                $field => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Pemetaan approver diperbarui.');
    }
}
