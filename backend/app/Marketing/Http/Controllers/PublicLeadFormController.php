<?php

namespace App\Marketing\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Marketing\Models\LeadForm;
use App\Marketing\Services\LeadFormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public endpoint - NO auth, NO RBAC. Akses by token saja.
 * URL: /api/v1/crm/public/forms/{token}        GET - return form schema
 *      /api/v1/crm/public/forms/{token}/submit POST - submit data
 *
 * CORS terbuka (handle di config/cors.php) supaya bisa di-embed dari landing
 * page di domain manapun.
 */
class PublicLeadFormController extends ApiController
{
    public function __construct(private readonly LeadFormService $forms) {}

    /** GET - return form schema untuk dirender di embed. */
    public function show(string $token): JsonResponse
    {
        $form = LeadForm::query()
            ->where('token', $token)
            ->where('is_active', true)
            ->first();

        if (! $form) {
            return response()->json(['message' => 'Form tidak ditemukan atau dinonaktifkan.'], 404);
        }

        // Public response - hanya expose field yang aman, BUKAN id/company_id/owner.
        return response()->json([
            'data' => [
                'token' => $form->token,
                'name' => $form->name,
                'description' => $form->description,
                'fields' => $form->fields,
                // success_message dikirim setelah submit, gak pre-fetched
            ],
        ]);
    }

    /** POST - submit form. Anti-spam handled di LeadFormService. */
    public function submit(Request $request, string $token): JsonResponse
    {
        $form = LeadForm::query()
            ->where('token', $token)
            ->where('is_active', true)
            ->first();

        if (! $form) {
            return response()->json(['message' => 'Form tidak ditemukan atau dinonaktifkan.'], 404);
        }

        try {
            $result = $this->forms->submit($form, $request->all(), $request);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if ($msg === 'spam_honeypot' || $msg === 'spam_rate_limit') {
                // Bot/spam - pura-pura sukses supaya gak ngasih tahu spammer kena filter
                return response()->json([
                    'data' => [
                        'success_message' => 'Terima kasih, kami akan hubungi segera.',
                    ],
                ]);
            }
            $decoded = json_decode($msg, true);
            if (is_array($decoded) && isset($decoded['validation_failed'])) {
                return response()->json([
                    'message' => 'Validasi gagal.',
                    'errors' => $decoded['validation_failed'],
                ], 422);
            }

            return response()->json(['message' => 'Submission gagal.'], 500);
        }

        return response()->json(['data' => $result]);
    }
}
