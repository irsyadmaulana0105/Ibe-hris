<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\WorkCertificate;
use App\Services\AuditLogService;
use App\Services\WorkCertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkCertificateController extends ApiController
{
    public function __construct(
        private readonly WorkCertificateService $service,
        private readonly AuditLogService $auditLog,
    ) {
    }

    /**
     * Karyawan self-issue surat keterangan kerja.
     * POST /api/v1/me/work-certificate → { data: certificate + download_url }
     */
    public function selfIssue(Request $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validate([
            'purpose' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $cert = $this->service->issue($user, $payload['purpose'] ?? null, null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->auditLog->log('work_certificate.self_issued', $user, 'work_certificate', $cert->id, [
            'cert_number' => $cert->cert_number,
        ], $request);

        return $this->success([
            'certificate'  => [
                'id'           => $cert->id,
                'cert_number'  => $cert->cert_number,
                'issued_date'  => $cert->issued_date->format('Y-m-d'),
                'purpose'      => $cert->purpose,
                'signer_name'  => $cert->signer_name,
                'download_url' => url("/api/v1/me/work-certificate/{$cert->id}/download"),
            ],
        ], 'Surat keterangan kerja berhasil diterbitkan.');
    }

    /** GET /api/v1/me/work-certificate - list surat yang pernah diterbitkan (karyawan) */
    public function selfList(Request $request): JsonResponse
    {
        $user = $request->user();
        $certs = WorkCertificate::where('user_id', $user->id)
            ->orderByDesc('issued_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($c) => [
                'id'           => $c->id,
                'cert_number'  => $c->cert_number,
                'issued_date'  => $c->issued_date->format('Y-m-d'),
                'purpose'      => $c->purpose,
                'signer_name'  => $c->signer_name,
                'download_url' => url("/api/v1/me/work-certificate/{$c->id}/download"),
            ]);

        return $this->success(['certificates' => $certs]);
    }

    /** GET /api/v1/me/work-certificate/{id}/download - download PDF */
    public function selfDownload(Request $request, int $id): StreamedResponse
    {
        $user = $request->user();
        $cert = WorkCertificate::where('id', $id)->where('user_id', $user->id)->firstOrFail();
        return $this->service->downloadResponse($cert);
    }

    /**
     * PREVIEW mode - render PDF tanpa write ke DB.
     * POST /api/v1/admin/work-certificates/preview → PDF stream
     *
     * User bisa review sebelum benar-benar issue. Cert_number pakai "PREVIEW"
     * placeholder + verification_hash dummy. Semua data lain (nama, tanggal,
     * TTD, stempel) sesuai real render.
     */
    public function adminPreview(Request $request): StreamedResponse
    {
        $admin = $request->user();
        $this->requireAdmin($admin);
        $payload = $request->validate([
            'user_id' => ['required', 'string'],
            'purpose' => ['nullable', 'string', 'max:500'],
        ]);

        $user = \App\Models\User::query()
            ->withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->findOrFail($payload['user_id']);

        if (!$user->join_date) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json(['message' => 'Tanggal bergabung karyawan belum di-set.'], 422)
            );
        }

        // Mock cert - TIDAK di-save ke DB, cuma untuk render preview.
        // PENTING: pakai forceFill supaya cast 'issued_date' => 'date' apply
        // (kalau langsung set via attribute assignment, cast miss dan
        // buildTemplateData jadi ERROR: Cannot access day on string).
        $mockCert = new \App\Models\WorkCertificate();
        $mockCert->forceFill([
            'id'                => 0,
            'company_id'        => $admin->company_id,
            'user_id'           => $user->id,
            'cert_number'       => 'PREVIEW/DRAFT',
            'purpose'           => $payload['purpose'] ?? null,
            'issued_date'       => now()->toDateString(),
            'signer_name'       => $user->company?->director_name ?? 'Aldy Iyik Be',
            'signer_title'      => 'Direktur Utama',
            'verification_hash' => 'preview',
        ]);
        $mockCert->setRelation('user', $user);
        $mockCert->setRelation('company', $user->company);

        @ini_set('memory_limit', '512M');
        @set_time_limit(60);

        return $this->service->downloadResponse($mockCert);
    }

    /**
     * Admin issue untuk user lain (kalau karyawan gak punya akses portal atau
     * butuh manual bulk).
     * POST /api/v1/admin/work-certificates → { user_id, purpose }
     */
    public function adminIssue(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireAdmin($admin);
        $payload = $request->validate([
            'user_id' => ['required', 'string'],
            'purpose' => ['nullable', 'string', 'max:500'],
        ]);

        $user = \App\Models\User::query()
            ->withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->findOrFail($payload['user_id']);

        try {
            $cert = $this->service->issue($user, $payload['purpose'] ?? null, $admin);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->auditLog->log('work_certificate.admin_issued', $admin, 'work_certificate', $cert->id, [
            'cert_number' => $cert->cert_number,
            'for_user_id' => $user->id,
        ], $request);

        return $this->success([
            'certificate' => [
                'id'           => $cert->id,
                'cert_number'  => $cert->cert_number,
                'download_url' => url("/api/v1/admin/work-certificates/{$cert->id}/download"),
            ],
        ], 'Surat keterangan kerja berhasil diterbitkan.');
    }

    /** GET /api/v1/admin/work-certificates/{id}/download (admin view any) */
    public function adminDownload(Request $request, int $id): StreamedResponse
    {
        $admin = $request->user();
        $this->requireAdmin($admin);
        $cert = WorkCertificate::query()
            ->where('company_id', $admin->company_id)
            ->findOrFail($id);
        return $this->service->downloadResponse($cert);
    }

    /**
     * Publik - verify surat dari QR code / URL.
     * GET /verify/skk/{hash} - public route (bukan API v1)
     */
    public function verify(string $hash)
    {
        $cert = WorkCertificate::where('verification_hash', $hash)->first();
        if (!$cert) {
            return response()->view('admin.certificates.verify', ['found' => false], 404);
        }
        return response()->view('admin.certificates.verify', [
            'found' => true,
            'cert' => $cert->load('user', 'company'),
        ]);
    }
}
