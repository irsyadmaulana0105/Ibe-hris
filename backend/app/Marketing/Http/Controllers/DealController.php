<?php

namespace App\Marketing\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Marketing\Http\Resources\DealResource;
use App\Marketing\Models\Deal;
use App\Marketing\Models\Stage;
use App\Marketing\Services\DealService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealController extends ApiController
{
    public function __construct(
        private readonly DealService $deals,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $deals = $this->deals
            ->listForUser($user, $request->only(['pipeline_id', 'stage_id', 'status', 'owner_id', 'q']))
            ->with(['stage', 'crmCompany', 'crmContact'])
            ->orderByDesc('updated_at')
            ->paginate(50);

        return $this->paginated($deals, DealResource::collection($deals)->resolve());
    }

    /** GET /crm/deals/board?pipeline_id=1 - semua deal grouped by stage (kanban view) */
    public function board(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $pipelineId = (int) $request->integer('pipeline_id');
        if (! $pipelineId) {
            return response()->json(['message' => 'pipeline_id required'], 422);
        }

        $stages = Stage::query()
            ->where('company_id', $user->company_id)
            ->where('pipeline_id', $pipelineId)
            ->orderBy('sort_order')
            ->get();

        // Dulu hanya status 'open' -> kolom Closed Won / Closed Lost di kanban
        // (dan diagram Workflow) SELALU kosong walau ada deal yang menang/kalah.
        // Deal tertutup ikut ditampilkan, tapi hanya yang ditutup 30 hari
        // terakhir supaya kolomnya tidak membengkak tanpa batas.
        $deals = Deal::query()
            ->where('company_id', $user->company_id)
            ->where('pipeline_id', $pipelineId)
            ->where(fn ($q) => $q->where('status', 'open')
                ->orWhere(fn ($c) => $c->whereIn('status', ['won', 'lost'])
                    ->where('closed_at', '>=', now()->subDays(30)->toDateString())))
            ->with(['crmCompany', 'crmContact'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $grouped = $deals->groupBy('stage_id');

        return $this->success([
            'stages' => $stages->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'color' => $s->color,
                'type' => $s->type,
                'sort_order' => $s->sort_order,
                'deals' => DealResource::collection($grouped->get($s->id, collect()))->resolve(),
                'count' => $grouped->get($s->id, collect())->count(),
                'total' => $grouped->get($s->id, collect())->sum('amount'),
            ])->values(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $deal = Deal::query()
            ->where('company_id', $user->company_id)
            ->with(['stage', 'crmCompany', 'crmContact', 'pipeline'])
            ->findOrFail($id);

        return $this->success([
            ...DealResource::make($deal)->resolve(),
            // Tombol "Terbitkan invoice" hanya untuk yang berhak (Keuangan).
            'can_issue_invoice' => $deal->status === 'won' && ! $deal->fin_invoice_id
                && $this->deals->canIssueInvoice($user),
        ]);
    }

    /**
     * POST /crm/deals/{id}/invoice - Keuangan menerbitkan draft invoice untuk
     * deal won yang ditandai menang oleh tim tanpa hak Keuangan.
     */
    public function issueInvoice(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireGranularCapability($user, 'finance.invoice.manage', 'finance.module.access',
            'Hanya tim Keuangan yang dapat menerbitkan invoice.');

        $deal = Deal::query()->where('company_id', $user->company_id)->findOrFail($id);
        $updated = $this->deals->issueInvoice($deal, $user);
        $this->audit->log('crm.deal.invoice_issued', $user, 'crm_deal', (string) $deal->id, [
            'fin_invoice_id' => $updated->fin_invoice_id,
        ]);

        $resource = DealResource::make($updated)->resolve();

        return $this->success([...$resource, 'can_issue_invoice' => false],
            "Draft invoice {$resource['fin_invoice_number']} diterbitkan.");
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'pipeline_id' => ['required', 'integer', 'exists:crm_pipelines,id'],
            'stage_id' => ['nullable', 'integer', 'exists:crm_stages,id'],
            ...$this->relationRules($user),
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'expected_close_date' => ['nullable', 'date'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $deal = $this->deals->create($user, $data);
        $this->audit->log('crm.deal.created', $user, 'crm_deal', (string) $deal->id, ['title' => $deal->title]);

        return $this->created(DealResource::make($deal)->resolve(), "Deal {$deal->code} dibuat.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $deal = Deal::query()->where('company_id', $user->company_id)->findOrFail($id);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            ...$this->relationRules($user),
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'expected_close_date' => ['nullable', 'date'],
            'probability' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);

        $deal->update($data);

        return $this->success(DealResource::make($deal->fresh(['stage', 'crmCompany', 'crmContact']))->resolve());
    }

    /** POST /crm/deals/{id}/move - pindah stage (kanban drag-drop). */
    public function move(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $data = $request->validate([
            'stage_id' => ['required', 'integer', 'exists:crm_stages,id'],
            'lost_reason' => ['nullable', 'string', 'max:100'],
        ]);

        $deal = Deal::query()->where('company_id', $user->company_id)->findOrFail($id);
        $stage = Stage::query()->where('company_id', $user->company_id)->findOrFail($data['stage_id']);

        $updated = $this->deals->changeStage($deal, $stage, $user, $data['lost_reason'] ?? null);
        $this->audit->log('crm.deal.stage_changed', $user, 'crm_deal', (string) $deal->id, [
            'stage_id' => $stage->id,
            'stage_type' => $stage->type,
        ]);

        return $this->success(DealResource::make($updated)->resolve(), "Deal pindah ke {$stage->name}.");
    }

    /**
     * Aturan relasi deal, dipakai bersama store/update.
     *
     * - company & kontak dibatasi ke tenant pengguna (dulu `exists` polos
     *   menerima id milik tenant lain).
     * - owner_id adalah ULID (teks). Dulu divalidasi `integer`, sehingga
     *   pemilik deal tidak pernah bisa diatur lewat API.
     *
     * @return array<string, array<int, mixed>>
     */
    private function relationRules(\App\Models\User $user): array
    {
        $tenant = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $user->company_id);

        return [
            'crm_company_id' => ['nullable', 'integer', $tenant('crm_companies')],
            'crm_contact_id' => ['nullable', 'integer', $tenant('crm_contacts')],
            'owner_id' => ['nullable', 'string', 'size:26', $tenant('users')],
        ];
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.admin');

        $deal = Deal::query()->where('company_id', $user->company_id)->findOrFail($id);
        $deal->delete();

        return $this->success(message: "Deal {$deal->code} dihapus.");
    }
}
