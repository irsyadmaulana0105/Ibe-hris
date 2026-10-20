<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CustomerSurvey;
use App\Services\CustomerSurveyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerSurveyController extends ApiController
{
    public function __construct(private readonly CustomerSurveyService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $companyId = (int) $request->user()->company_id;

        $rows = CustomerSurvey::query()
            ->where('company_id', $companyId)
            ->when($request->string('status')->toString(), fn ($w, $s) => $w->where('status', $s))
            ->when($request->string('category')->toString(), fn ($w, $c) => $w->where('nps_category', $c))
            ->orderByDesc('sent_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (CustomerSurvey $s) => [
                'id'             => $s->id,
                'survey_token'   => $s->survey_token,
                'customer_name'  => $s->customer_name,
                'customer_email' => $s->customer_email,
                'trigger_type'   => $s->trigger_type,
                'sent_date'      => $s->sent_date?->toDateString(),
                'responded_at'   => $s->responded_at?->toIso8601String(),
                'nps_score'      => $s->nps_score,
                'nps_category'   => $s->nps_category,
                'comment'        => $s->comment,
                'status'         => $s->status,
            ]);

        // NPS metric for last 90 days
        $from = now()->subDays(90)->toDateString();
        $to   = now()->toDateString();
        $nps  = $this->service->npsForPeriod($companyId, $from, $to);

        return $this->success(['data' => $rows, 'nps_90d' => $nps]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $data = $request->validate([
            'customer_id'       => ['nullable', 'integer'],
            'customer_name'     => ['required', 'string', 'max:200'],
            'customer_email'    => ['nullable', 'email', 'max:200'],
            'trigger_type'      => ['nullable', 'in:shipment_delivered,ticket_resolved,invoice_paid,manual'],
            'trigger_ref_table' => ['nullable', 'string', 'max:64'],
            'trigger_ref_id'    => ['nullable', 'integer'],
        ]);
        $survey = $this->service->send($request->user(), $data);
        return $this->created([
            'id'           => $survey->id,
            'survey_token' => $survey->survey_token,
            'public_url'   => url("/api/v1/public/surveys/{$survey->survey_token}"),
        ], 'Survey link dibuat.');
    }

    /** Public - GET /api/v1/public/surveys/{token} (no auth, untuk customer akses) */
    public function publicShow(Request $request, string $token): JsonResponse
    {
        $s = CustomerSurvey::where('survey_token', $token)->firstOrFail();
        if ($s->status === 'responded') {
            return response()->json(['message' => 'Survey sudah pernah diisi.', 'responded_at' => $s->responded_at?->toIso8601String()], 200);
        }
        if ($s->status === 'expired' || $s->status === 'cancelled') {
            return response()->json(['message' => 'Survey ini sudah tidak aktif.'], 410);
        }
        return $this->success([
            'survey_token'  => $s->survey_token,
            'customer_name' => $s->customer_name,
            'trigger_type'  => $s->trigger_type,
        ]);
    }

    /** Public - POST /api/v1/public/surveys/{token} (customer respond) */
    public function publicRespond(Request $request, string $token): JsonResponse
    {
        $s = CustomerSurvey::where('survey_token', $token)->firstOrFail();
        if ($s->status !== 'sent') {
            return response()->json(['message' => 'Survey tidak bisa diisi (status: ' . $s->status . ')'], 422);
        }
        $data = $request->validate([
            'nps_score' => ['required', 'integer', 'min:0', 'max:10'],
            'comment'   => ['nullable', 'string', 'max:2000'],
            'ratings'   => ['nullable', 'array'],
        ]);
        $survey = $this->service->recordResponse($s, $data);
        return $this->success([
            'nps_score'    => $survey->nps_score,
            'nps_category' => $survey->nps_category,
        ], 'Terima kasih atas feedback Anda!');
    }
}
