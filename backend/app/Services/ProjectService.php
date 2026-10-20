<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    public function __construct(
        private readonly TaskActivityLogger $logger,
        private readonly Notifier $notifier,
        private readonly ProjectAuthorizer $authorizer,
    ) {}

    /**
     * Get paginated projects for the authenticated user's company
     */
    public function indexForUser(
        User $user,
        int $perPage = 20,
        bool $includeArchived = false,
        bool $onlyArchived = false,
    ): LengthAwarePaginator {
        $q = Project::query()
            ->where('company_id', $user->company_id)
            ->with('creator:id,name,email')
            ->withCount(['tasks', 'tasks as completed_tasks_count' => function ($q) {
                $q->where('status', 'completed');
            }]);

        // Visibility = private by default: non-admin only sees projects where
        // they are a member. Workspace admin sees everything in their company.
        if (! $this->authorizer->isWorkspaceAdmin($user)) {
            $q->whereExists(function ($sub) use ($user) {
                $sub->select(DB::raw(1))
                    ->from('project_members')
                    ->whereColumn('project_members.project_id', 'projects.id')
                    ->where('project_members.user_id', $user->id);
            });
        }

        if ($onlyArchived) {
            $q->whereNotNull('archived_at');
        } elseif (! $includeArchived) {
            $q->whereNull('archived_at');
        }

        return $q->orderByDesc('updated_at')->paginate($perPage);
    }

    /**
     * Get a single project with its members and tasks (top-level only,
     * with assignee, subtasks_count, and comments_count for cards)
     */
    public function show(Project $project, User $user): Project
    {
        $this->authorizer->ensure($user, $project, 'view');

        // Expose viewer's effective role on this project so frontend can gate UI.
        // 'admin' = workspace admin, otherwise owner/lead/member/viewer.
        $project->setAttribute('my_role', $this->authorizer->roleFor($user, $project));
        $project->setAttribute('is_workspace_admin', $this->authorizer->isWorkspaceAdmin($user));

        return $project->load([
            'creator:id,name,email',
            'members.user:id,name,email,profile_photo_path,employment_status,resigned_at,last_working_date',
            'sprints' => function ($q) {
                $q->withCount(['tasks', 'tasks as completed_tasks_count' => function ($q2) {
                    $q2->where('status', 'completed');
                }])
                ->withSum('tasks as total_points', 'story_points')
                ->withSum(['tasks as completed_points' => function ($q2) {
                    $q2->where('status', 'completed');
                }], 'story_points')
                // Portable "sort by status enum order" - avoid MySQL-only FIELD()
                // so this query also runs on SQLite (used in test suite).
                ->orderByRaw("CASE status WHEN 'active' THEN 1 WHEN 'planned' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END")
                ->orderByDesc('start_date')
                ->orderByDesc('id');
            },
            'tasks' => function ($q) {
                $q->whereNull('parent_task_id')
                    ->with([
                        'assignee:id,name,profile_photo_path',
                        'labels:id,project_id,name,color',
                        'dependsOn:id,project_id,title,status',
                    ])
                    ->withCount(['subtasks', 'comments'])
                    // Portable "sort by priority enum order" - avoid MySQL-only FIELD().
                    ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
                    ->orderBy('due_date');
            },
            'labels',
        ]);
    }

    /**
     * Create a new project
     */
    public function create(array $data, User $creator): Project
    {
        $project = Project::create([
            'company_id' => $creator->company_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'planning',
            'template' => $data['template'] ?? 'kanban',
            'start_date' => $data['start_date'] ?? null,
            'end_date'   => $data['end_date'] ?? null,
            'created_by' => $creator->id,
        ]);

        // Auto-add creator as owner
        $project->members()->create([
            'user_id' => $creator->id,
            'role' => 'owner',
        ]);

        return $project;
    }

    /**
     * Update project details
     */
    public function update(Project $project, array $data, User $user): Project
    {
        $this->authorizeUserAccess($project, $user);

        $update = [];
        foreach (['name', 'description', 'status', 'template', 'start_date', 'end_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if ($update !== []) {
            $project->update($update);
        }

        return $project;
    }

    /**
     * Create a task in a project (or subtask when parent_task_id is set)
     */
    public function createTask(Project $project, array $data, User $creator): Task
    {
        $task = $project->tasks()->create([
            'parent_task_id' => $data['parent_task_id'] ?? null,
            'sprint_id' => $data['sprint_id'] ?? null,
            'goal_id' => $data['goal_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'task',
            'status' => $data['status'] ?? 'todo',
            'priority' => $data['priority'] ?? 'medium',
            'story_points' => $data['story_points'] ?? null,
            'time_estimate' => $data['time_estimate'] ?? null,
            'time_spent' => $data['time_spent'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'created_by' => $creator->id,
        ]);

        if (! empty($data['label_ids']) && is_array($data['label_ids'])) {
            $validIds = $project->labels()->whereIn('id', $data['label_ids'])->pluck('id');
            $task->labels()->sync($validIds);
        }

        $this->logger->created($task, $creator);

        $task->load(['assignee:id,name,profile_photo_path', 'labels:id,project_id,name,color']);
        $task->loadCount(['subtasks', 'comments']);

        return $task;
    }

    /**
     * Update a task. Uses array_key_exists so callers can clear nullable
     * fields (description, due_date, assigned_to) by sending null.
     */
    public function updateTask(Task $task, array $data, User $user): Task
    {
        $this->authorizeUserAccess($task->project, $user);

        $oldSnapshot = $task->only([
            'title', 'description', 'status', 'priority', 'type', 'story_points',
            'time_estimate', 'time_spent', 'start_date', 'due_date', 'assigned_to', 'sprint_id', 'parent_task_id',
        ]);

        $update = [];
        foreach (['title', 'description', 'type', 'status', 'priority', 'story_points', 'time_estimate', 'time_spent', 'assigned_to', 'start_date', 'due_date', 'sprint_id', 'goal_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if ($update !== []) {
            $task->update($update);
        }

        if (array_key_exists('label_ids', $data) && is_array($data['label_ids'])) {
            $oldLabels = $task->labels()->orderBy('name')->pluck('name')->implode(', ');
            $project = $task->project;
            $validIds = $project->labels()->whereIn('id', $data['label_ids'])->pluck('id');
            $task->labels()->sync($validIds);
            $newLabels = $task->labels()->orderBy('name')->pluck('name')->implode(', ');
            $this->logger->labelChanged($task, $user, $oldLabels, $newLabels);
        }

        $this->logger->updated($task, $user, $oldSnapshot);

        // Notify new assignee
        $newAssignee = $task->fresh()?->assigned_to;
        if ($newAssignee && $newAssignee !== ($oldSnapshot['assigned_to'] ?? null) && $newAssignee !== $user->id) {
            $this->notifier->notify(
                $user,
                $newAssignee,
                'assigned',
                ($user->name ?? 'Seseorang') . ' menugaskan Anda: "' . $task->title . '"',
                null,
                $task,
            );
        }

        $task->load(['assignee:id,name,profile_photo_path', 'labels:id,project_id,name,color']);
        $task->loadCount(['subtasks', 'comments']);

        return $task;
    }

    /**
     * Back-compat shim. Most callers should call ProjectAuthorizer directly
     * with the action they're performing. This still gates "can the user
     * see/touch this project at all?" - abort 404 if they have no role.
     */
    public function authorizeUserAccess(Project $project, User $user): void
    {
        $this->authorizer->ensure($user, $project, 'view');
    }
}
