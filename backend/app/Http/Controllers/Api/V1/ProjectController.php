<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectAuthorizer;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends ApiController
{
    public function __construct(
        private readonly ProjectService $service,
        private readonly ProjectAuthorizer $authorizer,
    ) {}

    /**
     * GET /api/v1/projects
     * List projects for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $includeArchived = $request->boolean('include_archived');
        $onlyArchived = $request->boolean('archived');

        $projects = $this->service->indexForUser($request->user(), 20, $includeArchived, $onlyArchived);

        return $this->paginated(
            $projects,
            $projects->items(),
            ['pending_task_count' => 0]
        );
    }

    /**
     * POST /api/v1/projects/{project}/archive
     */
    public function archive(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'archive');
        $project->update(['archived_at' => now()]);
        return $this->success($project, 'Projek diarsipkan.');
    }

    /**
     * POST /api/v1/projects/{project}/unarchive
     */
    public function unarchive(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'archive');
        $project->update(['archived_at' => null]);
        return $this->success($project, 'Projek dipulihkan.');
    }

    /**
     * DELETE /api/v1/projects/{project}
     */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'delete');
        $project->delete();
        return $this->success(message: 'Projek dihapus.');
    }

    /**
     * POST /api/v1/projects
     * Create a new project
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->authorizer->canCreateProject($request->user())) {
            abort(403, 'Hanya admin workspace yang bisa membuat projek baru.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(['planning', 'active', 'on_hold', 'completed', 'cancelled'])],
            'template' => ['nullable', Rule::in(['kanban', 'scrum'])],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $project = $this->service->create($data, $request->user());

        return $this->created($project, 'Project created successfully.');
    }

    /**
     * GET /api/v1/projects/{id}
     * Get a project with its members and tasks
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        $project = $this->service->show($project, $request->user());

        return $this->success($project);
    }

    /**
     * PUT /api/v1/projects/{id}
     * Update a project
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'edit');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'min:3', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(['planning', 'active', 'on_hold', 'completed', 'cancelled'])],
            'template' => ['nullable', Rule::in(['kanban', 'scrum'])],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $project = $this->service->update($project, $data, $request->user());

        return $this->success($project, 'Project updated.');
    }

    /**
     * GET /api/v1/projects/{projectId}/tasks/{taskId}
     * Fetch a single task with subtasks, assignee, and comments
     */
    public function showTask(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorizer->ensureTaskBelongs($project, $task);
        $this->service->authorizeUserAccess($project, $request->user());

        $task->load([
            'assignee:id,name,profile_photo_path',
            'labels:id,project_id,name,color',
            'dependsOn:id,project_id,title,status',
            'blocking:id,project_id,title,status',
            'goal:id,name,status',
            'attachments.user:id,name,profile_photo_path',
            'subtasks' => function ($q) {
                $q->with('assignee:id,name,profile_photo_path')
                    ->withCount('comments')
                    ->orderBy('created_at');
            },
            'comments.user:id,name,profile_photo_path',
            'activities' => function ($q) {
                $q->with('user:id,name,profile_photo_path')->limit(50);
            },
        ]);

        return $this->success($task);
    }

    /**
     * POST /api/v1/projects/{projectId}/tasks
     * Create a task (or subtask if parent_task_id provided) in a project
     */
    public function storeTask(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'create_task');

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['nullable', Rule::in(['task', 'story', 'bug', 'epic'])],
            'status' => ['nullable', Rule::in(['todo', 'in_progress', 'in_review', 'completed'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'story_points' => ['nullable', 'integer', 'min:0', 'max:255'],
            'time_estimate' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'time_spent' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'assigned_to' => $this->assigneeRules($project),
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'parent_task_id' => [
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')->where('project_id', $project->id),
            ],
            'sprint_id' => [
                'nullable',
                'integer',
                Rule::exists('sprints', 'id')->where('project_id', $project->id),
            ],
            'goal_id' => [
                'nullable',
                'integer',
                Rule::exists('goals', 'id')->where('company_id', $project->company_id),
            ],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')->where('project_id', $project->id)],
        ]);

        $task = $this->service->createTask($project, $data, $request->user());

        return $this->created($task, 'Task created.');
    }

    /**
     * PUT /api/v1/projects/{projectId}/tasks/{taskId}
     * Update a task
     */
    public function updateTask(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'edit_task', $task);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'min:3', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['nullable', Rule::in(['task', 'story', 'bug', 'epic'])],
            'status' => ['nullable', Rule::in(['todo', 'in_progress', 'in_review', 'completed'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'story_points' => ['nullable', 'integer', 'min:0', 'max:255'],
            'time_estimate' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'time_spent' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'assigned_to' => $this->assigneeRules($project),
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'sprint_id' => [
                'nullable',
                'integer',
                Rule::exists('sprints', 'id')->where('project_id', $project->id),
            ],
            'goal_id' => [
                'nullable',
                'integer',
                Rule::exists('goals', 'id')->where('company_id', $project->company_id),
            ],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')->where('project_id', $project->id)],
        ]);

        $task = $this->service->updateTask($task, $data, $request->user());

        return $this->success($task, 'Task updated.');
    }

    /**
     * DELETE /api/v1/projects/{projectId}/tasks/{taskId}
     * Delete a task
     */
    public function destroyTask(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'delete_task', $task);

        $task->delete();

        return $this->success(message: 'Task deleted.');
    }

    /**
     * POST /api/v1/projects/{project}/tasks/bulk
     * Body: { task_ids: number[], action: 'status'|'priority'|'assigned_to'|'sprint_id'|'delete', value?: any }
     *
     * Per-task authorization runs inside the loop so the API never partially
     * leaks: any unauthorized item aborts before any DB write happens. Tasks
     * outside the project are dropped (don't 404 the whole batch).
     */
    public function bulkUpdateTasks(Request $request, Project $project): JsonResponse
    {
        $allowed = ['status', 'priority', 'assigned_to', 'sprint_id', 'delete'];

        $data = $request->validate([
            'task_ids'      => ['required', 'array', 'min:1', 'max:200'],
            'task_ids.*'    => ['integer'],
            'action'        => ['required', Rule::in($allowed)],
            'value'         => ['nullable'],
        ]);

        $user = $request->user();
        $action = $data['action'];

        // Filter to tasks that actually belong to this project - silently drop
        // mismatches rather than 404-ing the whole batch.
        $tasks = Task::query()
            ->where('project_id', $project->id)
            ->whereIn('id', $data['task_ids'])
            ->get();

        if ($tasks->isEmpty()) {
            return $this->success(['updated' => 0]);
        }

        // Pre-flight authorization for every task - abort early if any one
        // is forbidden. Per-action permission mapping:
        //   delete → delete_task
        //   anything else → edit_task
        foreach ($tasks as $t) {
            $this->authorizer->ensure($user, $project, $action === 'delete' ? 'delete_task' : 'edit_task', $t);
        }

        // Validate per-action value shape.
        $value = $data['value'] ?? null;
        switch ($action) {
            case 'status':
                if (! in_array($value, ['todo', 'in_progress', 'in_review', 'completed'], true)) {
                    abort(422, 'Status tidak valid.');
                }
                break;
            case 'priority':
                if (! in_array($value, ['low', 'medium', 'high', 'urgent'], true)) {
                    abort(422, 'Prioritas tidak valid.');
                }
                break;
            case 'assigned_to':
                if ($value !== null && ! is_string($value)) {
                    abort(422, 'assigned_to harus string user ID atau null.');
                }
                if ($value !== null) {
                    $exists = \DB::table('users')
                        ->join('project_members', 'project_members.user_id', '=', 'users.id')
                        ->where('users.id', $value)
                        ->where('users.company_id', $project->company_id)
                        ->where('project_members.project_id', $project->id)
                        ->whereNotIn('users.employment_status', User::INACTIVE_STATUSES)
                        ->whereNull('users.resigned_at')
                        ->exists();
                    if (! $exists) abort(422, 'Assignee harus karyawan aktif dan member project ini.');
                }
                break;
            case 'sprint_id':
                if ($value !== null && ! is_int($value)) {
                    abort(422, 'sprint_id harus integer atau null.');
                }
                if ($value !== null) {
                    $exists = \DB::table('sprints')
                        ->where('id', $value)
                        ->where('project_id', $project->id)
                        ->exists();
                    if (! $exists) abort(422, 'Sprint tidak ditemukan di project ini.');
                }
                break;
            case 'delete':
                // no value needed
                break;
        }

        // Apply. Use service for status/priority/sprint/assigned so activity
        // log fires; raw delete for the delete action.
        $count = 0;
        foreach ($tasks as $t) {
            if ($action === 'delete') {
                $t->delete();
            } else {
                $this->service->updateTask($t, [$action => $value], $user);
            }
            $count++;
        }

        return $this->success([
            'updated' => $count,
            'action'  => $action,
        ], "$count task diupdate.");
    }

    private function assigneeRules(Project $project): array
    {
        return [
            'nullable',
            'string',
            Rule::exists('users', 'id')->where(fn ($query) => $query
                ->where('company_id', $project->company_id)
                ->whereNotIn('employment_status', User::INACTIVE_STATUSES)
                ->whereNull('resigned_at')),
            Rule::exists('project_members', 'user_id')->where('project_id', $project->id),
        ];
    }
}
