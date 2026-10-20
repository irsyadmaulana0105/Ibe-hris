<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\FaceApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FaceApprovalController extends ApiController
{
    public function __construct(
        private readonly FaceApprovalService $service,
    ) {}

    /**
     * GET /api/v1/admin/face-approvals
     * List users with pending face registration.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'face_approval.manage', 'Face approval access required.');

        $pending = $this->service
            ->listForApi($request->user())
            ->map(fn (User $u) => [
                'id'           => $u->id,
                'name'         => $u->name,
                'nip'          => $u->nip,
                'email'        => $u->email,
                'avatar'       => $u->profile_photo_url,
                'has_active'   => !is_null($u->face_descriptor),
                // Wajah yang SEBENARNYA didaftarkan (bukan foto profil) + foto acuan lama,
                // lewat URL bertanda tangan. Pendaftaran sebelum 19 Okt 2026 tidak punya foto.
                'enrollment_photo_url' => \App\Support\FotoWajah::url($u, 'pending'),
                'active_photo_url'     => \App\Support\FotoWajah::url($u, 'active'),
                'enrollment_quality'   => $u->face_quality_pending,
                'submitted_at'         => $u->updated_at?->toIso8601String(),
            ]);

        return $this->success($pending);
    }

    /**
     * POST /api/v1/admin/face-approvals/{id}/approve
     * Approve pending face - moves pending to active.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'face_approval.manage', 'Face approval access required.');

        $user = $this->service->approve($request->user(), $id);

        return $this->success(message: "Wajah {$user->name} berhasil disetujui.");
    }

    /**
     * POST /api/v1/admin/face-approvals/{id}/reject
     * Reject pending face - clears pending.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'face_approval.manage', 'Face approval access required.');

        $user = $this->service->reject($request->user(), $id);

        return $this->success(message: "Pendaftaran wajah {$user->name} ditolak.");
    }
}
