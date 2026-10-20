<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Goal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoalController extends ApiController
{
    private const STATUSES = ['on_track', 'at_risk', 'off_track', 'completed'];
    private const TYPES = ['objective', 'key_result'];

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $goals = Goal::query()
            ->where('company_id', $companyId)
            ->with('owner:id,name,profile_photo_path')
            ->withCount('children')
            ->withCount(['tasks', 'tasks as completed_tasks_count' => function ($q) {
                $q->where('status', 'completed');
            }])
            ->orderByDesc('updated_at')
            ->get();

        return $this->success($goals);
    }

    public function show(Request $request, Goal $goal): JsonResponse
    {
        $this->authorize($goal, $request, false);

        $goal->load([
            'owner:id,name,profile_photo_path',
            'parent:id,name,status',
            'children' => function ($q) {
                $q->withCount(['tasks', 'tasks as completed_tasks_count' => function ($qq) {
                    $qq->where('status', 'completed');
                }])->with('owner:id,name,profile_photo_path');
            },
            'tasks' => function ($q) {
                $q->with([
                    'assignee:id,name,profile_photo_path',
                    'project:id,name',
                ])
                ->orderBy('status')
                ->orderBy('priority');
            },
        ]);

        return $this->success($goal);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name'             => ['required', 'string', 'min:2', 'max:200'],
            'description'      => ['nullable', 'string', 'max:2000'],
            'status'           => ['nullable', Rule::in(self::STATUSES)],
            'type'             => ['nullable', Rule::in(self::TYPES)],
            'focus_area'       => ['nullable', 'string', 'max:100'],
            'parent_goal_id'   => ['nullable', 'integer', Rule::exists('goals', 'id')->where('company_id', $user->company_id)],
            'owner_id'         => ['nullable', 'string', Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $user->company_id)->whereNotIn('employment_status', User::INACTIVE_STATUSES)->whereNull('resigned_at'))],
            'progress_metric'  => ['nullable', 'string', 'max:200'],
            'progress_value'   => ['nullable', 'integer', 'min:0', 'max:100'],
            'start_date'       => ['nullable', 'date'],
            'end_date'         => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $goal = Goal::create([
            'company_id'      => $user->company_id,
            'name'            => $data['name'],
            'description'     => $data['description'] ?? null,
            'status'          => $data['status'] ?? 'on_track',
            'type'            => $data['type'] ?? 'objective',
            'focus_area'      => $data['focus_area'] ?? null,
            'parent_goal_id'  => $data['parent_goal_id'] ?? null,
            'owner_id'        => $data['owner_id'] ?? $user->id,
            'progress_metric' => $data['progress_metric'] ?? null,
            'progress_value'  => $data['progress_value'] ?? null,
            'start_date'      => $data['start_date'] ?? null,
            'end_date'        => $data['end_date'] ?? null,
            'created_by'      => $user->id,
        ]);

        return $this->created($goal, 'Goal dibuat.');
    }

    public function update(Request $request, Goal $goal): JsonResponse
    {
        $this->authorize($goal, $request);
        $user = $request->user();

        $data = $request->validate([
            'name'             => ['nullable', 'string', 'min:2', 'max:200'],
            'description'      => ['nullable', 'string', 'max:2000'],
            'status'           => ['nullable', Rule::in(self::STATUSES)],
            'type'             => ['nullable', Rule::in(self::TYPES)],
            'focus_area'       => ['nullable', 'string', 'max:100'],
            'parent_goal_id'   => ['nullable', 'integer', Rule::exists('goals', 'id')->where('company_id', $user->company_id)],
            'owner_id'         => ['nullable', 'string', Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $user->company_id)->whereNotIn('employment_status', User::INACTIVE_STATUSES)->whereNull('resigned_at'))],
            'progress_metric'  => ['nullable', 'string', 'max:200'],
            'progress_value'   => ['nullable', 'integer', 'min:0', 'max:100'],
            'start_date'       => ['nullable', 'date'],
            'end_date'         => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        if (isset($data['parent_goal_id']) && $data['parent_goal_id'] === $goal->id) {
            abort(422, 'Goal tidak bisa menjadi parent dari dirinya sendiri.');
        }
        if (isset($data['parent_goal_id']) && $this->createsCycle($goal, (int) $data['parent_goal_id'])) {
            abort(422, 'Relasi parent akan membuat cycle.');
        }

        $update = [];
        foreach (['name', 'description', 'status', 'type', 'focus_area', 'parent_goal_id', 'owner_id', 'progress_metric', 'progress_value', 'start_date', 'end_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if ($update !== []) {
            $goal->update($update);
        }

        return $this->success($goal->fresh(['owner:id,name,profile_photo_path']));
    }

    public function destroy(Request $request, Goal $goal): JsonResponse
    {
        $this->authorize($goal, $request);

        // Detach tasks instead of cascade-deleting them
        Task::query()
            ->withoutGlobalScopes()
            ->where('goal_id', $goal->id)
            ->update(['goal_id' => null]);

        $goal->delete();

        return $this->success(message: 'Goal dihapus.');
    }

    private function authorize(Goal $goal, Request $request, bool $requireManage = true): void
    {
        $user = $request->user();
        if ($goal->company_id !== $user->company_id) {
            abort(403, 'Unauthorized.');
        }

        if ($requireManage && ! $user->isAdmin && $goal->owner_id !== $user->id && $goal->created_by !== $user->id) {
            abort(403, 'Hanya pemilik, pembuat, atau admin yang dapat mengubah goal.');
        }
    }

    private function createsCycle(Goal $goal, int $parentId): bool
    {
        $visited = [];
        while ($parentId !== 0 && ! isset($visited[$parentId])) {
            if ($parentId === $goal->id) {
                return true;
            }
            $visited[$parentId] = true;
            $parentId = (int) Goal::query()
                ->withoutGlobalScopes()
                ->where('company_id', $goal->company_id)
                ->whereKey($parentId)
                ->value('parent_goal_id');
        }

        return false;
    }
}
