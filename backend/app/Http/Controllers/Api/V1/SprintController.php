<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Project;
use App\Models\Sprint;
use App\Services\ProjectAuthorizer;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SprintController extends ApiController
{
    public function __construct(
        private readonly ProjectService $service,
        private readonly ProjectAuthorizer $authorizer,
    ) {}

    /**
     * GET /api/v1/projects/{project}/sprints
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->service->authorizeUserAccess($project, $request->user());

        $sprints = $project->sprints()
            ->withCount(['tasks', 'tasks as completed_tasks_count' => function ($q) {
                $q->where('status', 'completed');
            }])
            ->withSum('tasks as total_points', 'story_points')
            ->withSum(['tasks as completed_points' => function ($q) {
                $q->where('status', 'completed');
            }], 'story_points')
            ->orderByRaw("FIELD(status, 'active', 'planned', 'completed')")
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return $this->success($sprints);
    }

    /**
     * POST /api/v1/projects/{project}/sprints
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_sprints');

        $data = $request->validate([
            'name'       => ['required', 'string', 'min:1', 'max:100'],
            'goal'       => ['nullable', 'string', 'max:1000'],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $sprint = $project->sprints()->create([
            'name'       => $data['name'],
            'goal'       => $data['goal'] ?? null,
            'status'     => 'planned',
            'start_date' => $data['start_date'] ?? null,
            'end_date'   => $data['end_date'] ?? null,
        ]);

        return $this->created($sprint, 'Sprint created.');
    }

    /**
     * PUT /api/v1/projects/{project}/sprints/{sprint}
     */
    public function update(Request $request, Project $project, Sprint $sprint): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_sprints');
        $this->ensureBelongs($sprint, $project);

        $data = $request->validate([
            'name'       => ['nullable', 'string', 'min:1', 'max:100'],
            'goal'       => ['nullable', 'string', 'max:1000'],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $update = [];
        foreach (['name', 'goal', 'start_date', 'end_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if ($update !== []) {
            $sprint->update($update);
        }

        return $this->success($sprint->fresh());
    }

    /**
     * POST /api/v1/projects/{project}/sprints/{sprint}/start
     * Start a planned sprint. Only one active sprint per project.
     */
    public function start(Request $request, Project $project, Sprint $sprint): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_sprints');
        $this->ensureBelongs($sprint, $project);

        if ($sprint->status !== 'planned') {
            abort(422, 'Hanya sprint berstatus planned yang dapat di-start.');
        }

        $activeExists = $project->sprints()->where('status', 'active')->exists();
        if ($activeExists) {
            abort(422, 'Sudah ada sprint aktif. Selesaikan dulu sebelum start sprint baru.');
        }

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $sprint->update([
            'status'     => 'active',
            'start_date' => $data['start_date'],
            'end_date'   => $data['end_date'],
        ]);

        return $this->success($sprint->fresh(), 'Sprint dimulai.');
    }

    /**
     * POST /api/v1/projects/{project}/sprints/{sprint}/complete
     * Complete an active sprint. Incomplete tasks move back to backlog
     * (sprint_id = null) unless a destination sprint is given.
     */
    public function complete(Request $request, Project $project, Sprint $sprint): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_sprints');
        $this->ensureBelongs($sprint, $project);

        if ($sprint->status !== 'active') {
            abort(422, 'Hanya sprint aktif yang dapat di-complete.');
        }

        $data = $request->validate([
            'move_to_sprint_id' => [
                'nullable',
                'integer',
                Rule::exists('sprints', 'id')->where('project_id', $project->id),
            ],
        ]);

        $destination = $data['move_to_sprint_id'] ?? null;

        $sprint->tasks()
            ->where('status', '!=', 'completed')
            ->update(['sprint_id' => $destination]);

        $sprint->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        return $this->success($sprint->fresh(), 'Sprint diselesaikan.');
    }

    /**
     * DELETE /api/v1/projects/{project}/sprints/{sprint}
     * Only planned sprints can be deleted. Active/completed must be archived
     * by completing first, since they contain historical data.
     */
    public function destroy(Request $request, Project $project, Sprint $sprint): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_sprints');
        $this->ensureBelongs($sprint, $project);

        if ($sprint->status !== 'planned') {
            abort(422, 'Hanya sprint planned yang dapat dihapus.');
        }

        // Tasks in this sprint go back to backlog
        $sprint->tasks()->update(['sprint_id' => null]);
        $sprint->delete();

        return $this->success(message: 'Sprint deleted.');
    }

    private function ensureBelongs(Sprint $sprint, Project $project): void
    {
        if ($sprint->project_id !== $project->id) {
            abort(404);
        }
    }
}
