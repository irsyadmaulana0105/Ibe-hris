<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI/legal helpers for the Recruitment flow:
 *   - parseCv: upload CV file → extract structured candidate fields via OpenAI
 *   - issueOffer: render offer letter PDF + apply Peruri e-meterai stamp
 */
class RecruitmentAiController extends ApiController
{
    /**
     * POST /api/v1/admin/recruitment/candidates/parse-cv
     * Multipart upload: cv_file (PDF or TXT). Returns parsed fields or
     * a graceful error message if OpenAI integration is not configured.
     */
    public function parseCv(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'recruitment.manage', 'Recruitment access required.');

        return response()->json([
            'message' => 'Upload CV dinonaktifkan sementara sampai penyimpanan privat tersedia.',
            'feature_enabled' => (bool) config('sensitive_file_features.recruitment_cv', false),
        ], 503);
    }

    /**
     * POST /api/v1/admin/recruitment/candidates/{id}/issue-offer
     * Body: pdf_path (path to existing offer letter PDF under storage/app/public).
     * Returns Peruri-stamped PDF path or graceful error if integration missing.
     */
    public function issueOffer(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'recruitment.manage', 'Recruitment access required.');

        return response()->json([
            'message' => 'Penerbitan offer PDF dinonaktifkan sementara sampai dokumen privat tersedia.',
            'feature_enabled' => (bool) config('sensitive_file_features.recruitment_offer_pdf', false),
        ], 503);
    }
}
