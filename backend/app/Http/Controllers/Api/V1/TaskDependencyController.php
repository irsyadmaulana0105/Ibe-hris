<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Project;
use App\Models\Task;
use App\Services\ProjectAuthorizer;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskDependencyController extends ApiController
{
    public function __construct(
        private readonly ProjectService $service,
        private readonly ProjectAuthorizer $authorizer,
    ) {}

    /**
     * POST /api/v1/projects/{project}/tasks/{task}/dependencies
     * Body: { depends_on_task_id }
     */
    public function store(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'edit_task', $task);

        $data = $request->validate([
            'depends_on_task_id' => [
                'required',
                'integer',
                'different:task_id',
                Rule::exists('tasks', 'id')->where('project_id', $project->id),
            ],
        ]);

        $depId = (int) $data['depends_on_task_id'];

        if ($depId === $task->id) {
            abort(422, 'Task tidak bisa depend pada dirinya sendiri.');
        }

        // Prevent cycles: depId must not transitively depend on $task
        if ($this->createsCycle($task->id, $depId)) {
            abort(422, 'Dependency akan membuat cycle. Tidak diizinkan.');
        }

        // Idempotent attach
        $task->dependsOn()->syncWithoutDetaching([$depId]);

        $task->load(['dependsOn:id,project_id,title,status', 'blocking:id,project_id,title,status']);

        return $this->created($task, 'Dependency ditambahkan.');
    }

    /**
     * DELETE /api/v1/projects/{project}/tasks/{task}/dependencies/{dep}
     */
    public function destroy(Request $request, Project $project, Task $task, int $dep): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'edit_task', $task);

        $task->dependsOn()->detach($dep);

        return $this->success(message: 'Dependency dihapus.');
    }

    /**
     * Walk the dependency graph starting from $candidate - if we ever
     * arrive back at $taskId, adding the edge would form a cycle.
     */
    private function createsCycle(int $taskId, int $candidate): bool
    {
        $visited = [];
        $stack = [$candidate];
        while ($stack) {
            $current = array_pop($stack);
            if (isset($visited[$current])) continue;
            $visited[$current] = true;
            if ($current === $taskId) return true;

            $next = Task::query()
                ->where('id', $current)
                ->with('dependsOn:id')
                ->first()?->dependsOn
                ->pluck('id')
                ->all() ?? [];
            foreach ($next as $n) {
                if (! isset($visited[$n])) $stack[] = $n;
            }
        }
        return false;
    }
}
