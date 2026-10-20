<?php

namespace App\Marketing\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Marketing\Http\Resources\DealResource;
use App\Marketing\Http\Resources\LeadResource;
use App\Marketing\Models\Lead;
use App\Marketing\Services\LeadService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends ApiController
{
    public function __construct(
        private readonly LeadService $leads,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $leads = $this->leads
            ->listForUser($user, $request->only(['status', 'owner_id', 'q']))
            ->orderByDesc('created_at')
            ->paginate(50);

        return $this->paginated($leads, LeadResource::collection($leads)->resolve());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $lead = Lead::query()->where('company_id', $user->company_id)->findOrFail($id);

        return $this->success(LeadResource::make($lead)->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'source' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'budget_score' => ['nullable', 'integer', 'min:0', 'max:25'],
            'authority_score' => ['nullable', 'integer', 'min:0', 'max:25'],
            'need_score' => ['nullable', 'integer', 'min:0', 'max:25'],
            'timeline_score' => ['nullable', 'integer', 'min:0', 'max:25'],
        ]);
        // Auto-compute total score
        $data['score'] = (int) ($data['budget_score'] ?? 0)
                       + (int) ($data['authority_score'] ?? 0)
                       + (int) ($data['need_score'] ?? 0)
                       + (int) ($data['timeline_score'] ?? 0);

        $lead = $this->leads->create($user, $data);
        $this->audit->log('crm.lead.created', $user, 'crm_lead', (string) $lead->id, ['name' => $lead->name]);

        return $this->created(LeadResource::make($lead)->resolve(), 'Lead dibuat.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $lead = Lead::query()->where('company_id', $user->company_id)->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'source' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'in:new,contacted,qualified,disqualified'],
            'notes' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'budget_score' => ['nullable', 'integer', 'min:0', 'max:25'],
            'authority_score' => ['nullable', 'integer', 'min:0', 'max:25'],
            'need_score' => ['nullable', 'integer', 'min:0', 'max:25'],
            'timeline_score' => ['nullable', 'integer', 'min:0', 'max:25'],
        ]);
        // Re-compute score kalau ada BANT field yang diubah
        $hasBantUpdate = array_intersect_key($data, array_flip(['budget_score', 'authority_score', 'need_score', 'timeline_score']));
        if (! empty($hasBantUpdate)) {
            $data['score'] = (int) ($data['budget_score'] ?? $lead->budget_score ?? 0)
                           + (int) ($data['authority_score'] ?? $lead->authority_score ?? 0)
                           + (int) ($data['need_score'] ?? $lead->need_score ?? 0)
                           + (int) ($data['timeline_score'] ?? $lead->timeline_score ?? 0);
        }

        $lead->update($data);

        return $this->success(LeadResource::make($lead->fresh())->resolve());
    }

    /** POST /crm/leads/{id}/convert - promote lead jadi deal */
    public function convert(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $lead = Lead::query()->where('company_id', $user->company_id)->findOrFail($id);
        if ($lead->converted_deal_id) {
            return response()->json(['message' => 'Lead sudah pernah di-convert.'], 422);
        }

        $dealData = $request->validate([
            'pipeline_id' => ['required', 'integer', 'exists:crm_pipelines,id'],
            'title' => ['nullable', 'string', 'max:200'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            // Dibatasi ke tenant pengguna (dulu `exists` polos).
            'crm_company_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('crm_companies', 'id')->where('company_id', $user->company_id)],
            'crm_contact_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('crm_contacts', 'id')->where('company_id', $user->company_id)],
        ]);

        $deal = $this->leads->convertToDeal($lead, $user, $dealData);
        $this->audit->log('crm.lead.converted', $user, 'crm_lead', (string) $lead->id, ['deal_id' => $deal->id]);

        return $this->created(DealResource::make($deal)->resolve(), "Lead di-convert jadi deal {$deal->code}.");
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.admin');

        $lead = Lead::query()->where('company_id', $user->company_id)->findOrFail($id);
        $lead->delete();

        return $this->success(message: 'Lead dihapus.');
    }
}
