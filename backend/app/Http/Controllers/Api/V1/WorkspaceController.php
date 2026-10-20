<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Goal;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Services\ProjectAuthorizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aggregated workspace-level endpoints - cross-project views that the
 * left sidebar pages (Dashboard, My Tasks) hit instead of pulling each
 * project individually.
 *
 * Access control: workspace admins see all company data. Non-admins only
 * see data scoped to projects they are members of. The two helpers below
 * (`projectScope`, `restrictToVisible`) centralize that filter so it
 * applies uniformly across every widget.
 */
class WorkspaceController extends ApiController
{
    public function __construct(private readonly ProjectAuthorizer $authorizer) {}

    /**
     * GET /api/v1/workspace/my-tasks
     */
    public function myTasks(Request $request): JsonResponse
    {
        $user = $request->user();

        $tasks = Task::query()
            ->whereHas('project', $this->projectScope($user))
            ->where('assigned_to', $user->id)
            ->with([
                'project:id,name',
                'labels:id,project_id,name,color',
            ])
            ->orderByRaw("FIELD(status, 'in_progress', 'in_review', 'todo', 'completed')")
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
            ->orderBy('due_date')
            ->get();

        return $this->success($tasks);
    }

    /**
     * GET /api/v1/workspace/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;
        $projectScope = $this->projectScope($user);
        $isAdmin = $this->authorizer->isWorkspaceAdmin($user);

        $projects = Project::query()
            ->where('company_id', $companyId)
            ->whereNull('archived_at')
            ->when(! $isAdmin, function ($q) use ($user) {
                $q->whereHas('members', function ($m) use ($user) {
                    $m->where('user_id', $user->id);
                });
            })
            ->withCount(['tasks', 'tasks as completed_tasks_count' => function ($q) {
                $q->where('status', 'completed');
            }])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        $myOpenTasks = Task::query()
            ->whereHas('project', $projectScope)
            ->where('assigned_to', $user->id)
            ->where('status', '!=', 'completed')
            ->with(['project:id,name'])
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
            ->orderBy('due_date')
            ->limit(10)
            ->get();

        $upcoming = Task::query()
            ->whereHas('project', $projectScope)
            ->where('status', '!=', 'completed')
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(14)->toDateString()])
            ->with(['project:id,name', 'assignee:id,name,profile_photo_path'])
            ->orderBy('due_date')
            ->limit(10)
            ->get();

        $overdueCount = Task::query()
            ->whereHas('project', $projectScope)
            ->where('status', '!=', 'completed')
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->count();

        $recentActivity = TaskActivity::query()
            ->whereHas('task.project', $projectScope)
            ->with([
                'user:id,name,profile_photo_path',
                'task:id,project_id,title',
                'task.project:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        // Goals are company-scoped (workspace-level), not project-scoped,
        // so everyone in the company sees the same goal status overview.
        $goalSummary = Goal::query()
            ->where('company_id', $companyId)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $projectCount = Project::query()
            ->where('company_id', $companyId)
            ->whereNull('archived_at')
            ->when(! $isAdmin, function ($q) use ($user) {
                $q->whereHas('members', function ($m) use ($user) {
                    $m->where('user_id', $user->id);
                });
            })
            ->count();

        $openTaskCount = Task::query()
            ->whereHas('project', $projectScope)
            ->where('status', '!=', 'completed')
            ->count();

        return $this->success([
            'project_count'    => $projectCount,
            'open_task_count'  => $openTaskCount,
            'my_open_count'    => $myOpenTasks->count(),
            'overdue_count'    => $overdueCount,
            'goal_summary'     => $goalSummary,
            'projects'         => $projects,
            'my_open_tasks'    => $myOpenTasks,
            'upcoming'         => $upcoming,
            'recent_activity'  => $recentActivity,
        ]);
    }

    /**
     * Closure that constrains a project sub-query to:
     *   - same company
     *   - not archived
     *   - user is member (skipped for workspace admins)
     */
    private function projectScope($user): \Closure
    {
        $isAdmin = $this->authorizer->isWorkspaceAdmin($user);
        return function (Builder $q) use ($user, $isAdmin) {
            $q->where('company_id', $user->company_id)->whereNull('archived_at');
            if (! $isAdmin) {
                $q->whereHas('members', function ($m) use ($user) {
                    $m->where('user_id', $user->id);
                });
            }
        };
    }
}
