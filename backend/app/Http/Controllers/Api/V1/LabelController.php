<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Label;
use App\Models\Project;
use App\Services\ProjectAuthorizer;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LabelController extends ApiController
{
    private const COLORS = [
        'slate', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald',
        'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'pink', 'rose',
    ];

    public function __construct(
        private readonly ProjectService $service,
        private readonly ProjectAuthorizer $authorizer,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->service->authorizeUserAccess($project, $request->user());

        $labels = $project->labels()->orderBy('name')->get();

        return $this->success($labels);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_labels');

        $data = $request->validate([
            'name'  => ['required', 'string', 'min:1', 'max:50'],
            'color' => ['nullable', 'string', Rule::in(self::COLORS)],
        ]);

        $label = $project->labels()->create([
            'name'  => $data['name'],
            'color' => $data['color'] ?? 'slate',
        ]);

        return $this->created($label, 'Label dibuat.');
    }

    public function update(Request $request, Project $project, Label $label): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_labels');
        $this->ensureBelongs($label, $project);

        $data = $request->validate([
            'name'  => ['nullable', 'string', 'min:1', 'max:50'],
            'color' => ['nullable', 'string', Rule::in(self::COLORS)],
        ]);

        $update = [];
        foreach (['name', 'color'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if ($update !== []) {
            $label->update($update);
        }

        return $this->success($label->fresh());
    }

    public function destroy(Request $request, Project $project, Label $label): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_labels');
        $this->ensureBelongs($label, $project);

        $label->delete();

        return $this->success(message: 'Label dihapus.');
    }

    private function ensureBelongs(Label $label, Project $project): void
    {
        if ($label->project_id !== $project->id) {
            abort(404);
        }
    }
}
