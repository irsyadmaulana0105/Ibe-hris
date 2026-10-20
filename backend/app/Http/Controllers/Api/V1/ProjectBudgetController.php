<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Project;
use App\Models\ProjectBudgetItem;
use App\Services\ProjectAuthorizer;
use App\Services\ProjectBudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectBudgetController extends ApiController
{
    public function __construct(private readonly ProjectBudgetService $service, private readonly ProjectAuthorizer $authorizer) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'view');

        return $this->success($this->service->detail($project));
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_budget');
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'], 'tax_percent' => ['required', 'numeric', 'between:0,100'],
            'contingency_percent' => ['required', 'numeric', 'between:0,100'], 'status' => ['required', Rule::in(['draft', 'locked'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $this->service->saveSettings($project, $data, $request->user());

        return $this->success($this->service->detail($project), 'Pengaturan RAB disimpan.');
    }

    public function storeItem(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_budget');
        $item = $this->service->addItem($project, $this->validateItem($request), $request->user());

        return $this->created($item, 'Item RAB ditambahkan.');
    }

    public function updateItem(Request $request, Project $project, ProjectBudgetItem $item): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_budget');

        return $this->success($this->service->updateItem($project, $item, $this->validateItem($request, $item), $request->user()), 'Item RAB diperbarui.');
    }

    public function destroyItem(Request $request, Project $project, ProjectBudgetItem $item): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_budget');
        $this->service->deleteItem($project, $item, $request->user());

        return $this->success(message: 'Item RAB dihapus.');
    }

    public function submit(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_budget');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $this->service->submit($project, $request->user(), $data['reason'] ?? null);

        return $this->success($this->service->detail($project), 'RAB diajukan untuk approval.');
    }

    public function decide(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'approve_budget');
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['nullable', 'string', 'max:2000']]);
        $this->service->approve($project, $request->user(), $data['decision'], $data['reason'] ?? null);

        return $this->success($this->service->detail($project), $data['decision'] === 'approve' ? 'Baseline RAB disetujui.' : 'RAB ditolak.');
    }

    public function storeProgress(Request $request, Project $project, ProjectBudgetItem $item): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_budget');
        $data = $request->validate([
            'entry_date' => ['required', 'date'], 'planned_percent' => ['required', 'numeric', 'between:0,100'],
            'actual_percent' => ['required', 'numeric', 'between:0,100'], 'actual_cost' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'], 'evidence_url' => ['prohibited'],
        ]);
        $entry = $this->service->saveProgress($project, $item, $data, $request->user());

        return $this->success($entry, 'Progres RAB disimpan.');
    }

    private function validateItem(Request $request, ?ProjectBudgetItem $item = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50'], 'category' => ['required', Rule::in(['material', 'labor', 'equipment', 'subcontract', 'overhead', 'other'])],
            'description' => ['required', 'string', 'max:255'], 'unit' => ['required', 'string', 'max:30'],
            'volume' => ['required', 'numeric', 'gt:0'], 'unit_price' => ['required', 'numeric', 'min:0'],
            'planned_start' => ['nullable', 'date'], 'planned_end' => ['nullable', 'date', 'after_or_equal:planned_start'],
            'sort_order' => ['sometimes', 'integer', 'min:0'], 'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
