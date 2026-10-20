<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\GovernanceRecord;
use App\Services\GovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GovernanceController extends ApiController
{
    private const TYPES = ['risk', 'capa', 'recovery_test', 'continuity_exercise', 'accessibility_audit'];

    private const STATUSES = ['open', 'assessing', 'treating', 'scheduled', 'in_progress', 'closed', 'verified'];

    public function __construct(private readonly GovernanceService $governance) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(self::TYPES)], 'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->governance->list($request->user()->company_id, $filters);

        return $this->paginated($page, $page->getCollection());
    }

    public function summary(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());

        return $this->success($this->governance->summary($request->user()->company_id));
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $data = $this->validated($request);

        return $this->created($this->governance->create($request->user(), $data, $request), 'Governance record dibuat.');
    }

    public function update(Request $request, GovernanceRecord $record): JsonResponse
    {
        $this->requireAdmin($request->user());
        $data = $this->validated($request, true);

        return $this->success($this->governance->update($request->user(), $record, $data, $request), 'Governance record diperbarui.');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'type' => [$required, Rule::in(self::TYPES)], 'reference' => ['nullable', 'string', 'max:64'],
            'title' => [$required, 'string', 'max:255'], 'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(self::STATUSES)], 'severity' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'likelihood' => ['nullable', 'integer', 'between:1,5'], 'impact' => ['nullable', 'integer', 'between:1,5'],
            'owner_id' => ['nullable', 'string', 'size:26', Rule::exists('users', 'id')->where(
                fn ($query) => $query->where('company_id', $request->user()->company_id)
            )], 'due_date' => ['nullable', 'date'],
            'details' => ['nullable', 'array'], 'evidence' => ['nullable', 'array'],
        ]);
    }
}
