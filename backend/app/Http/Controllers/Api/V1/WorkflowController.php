<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'workflow.manage', 'Workflow access required.');
        return $this->success(WorkflowDefinition::query()->with('steps')->orderBy('id')->get());
    }

    public function storeStep(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'workflow.manage', 'Workflow access required.');
        $data = $request->validate([
            'workflow_definition_id' => ['required', 'integer', 'exists:workflow_definitions,id'],
            'label' => ['required', 'string', 'max:100'],
            'role'  => ['required', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);
        $maxSort = WorkflowStep::where('workflow_definition_id', $data['workflow_definition_id'])->max('sort_order') ?? 0;
        $step = WorkflowStep::create([...$data, 'sort_order' => $maxSort + 10]);
        return $this->created($step, 'Step ditambahkan.');
    }

    public function updateStep(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'workflow.manage', 'Workflow access required.');
        $step = WorkflowStep::findOrFail($id);
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:100'],
            'role'  => ['sometimes', 'string', 'max:50'],
            'color' => ['sometimes', 'string', 'max:20'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
        $step->update($data);
        return $this->success($step->fresh(), 'Step diperbarui.');
    }

    public function destroyStep(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'workflow.manage', 'Workflow access required.');
        WorkflowStep::findOrFail($id)->delete();
        return $this->success(message: 'Step dihapus.');
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'workflow.manage', 'Workflow access required.');
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:workflow_steps,id'],
        ]);
        foreach ($data['order'] as $idx => $id) {
            WorkflowStep::where('id', $id)->update(['sort_order' => ($idx + 1) * 10]);
        }
        return $this->success(message: 'Urutan disimpan.');
    }
}
