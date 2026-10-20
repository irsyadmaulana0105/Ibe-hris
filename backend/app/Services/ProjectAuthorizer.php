<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;

/**
 * Single source of truth for "can this user do X on this project?".
 *
 * Two tiers stacked together:
 *   1. WORKSPACE ADMIN - User::isAdmin (group ∈ admin/superadmin/finance).
 *      Bypasses everything. Workspace admins manage every project regardless
 *      of membership. Aligned with the existing Absen V2 HR admin role.
 *   2. PROJECT ROLES - owner > lead > member > viewer. Stored on
 *      project_members.role. Non-admin users only see projects where they
 *      have a row in project_members.
 *
 * All controllers MUST go through this class for authorization. The legacy
 * `ProjectService::authorizeUserAccess()` only checked company_id, which
 * effectively made every project visible to every coworker - fixed here.
 */
class ProjectAuthorizer
{
    public const ROLE_OWNER  = 'owner';
    public const ROLE_LEAD   = 'lead';
    public const ROLE_MEMBER = 'member';
    public const ROLE_VIEWER = 'viewer';

    // Rank used for ">=" comparisons.
    private const RANK = [
        self::ROLE_VIEWER => 1,
        self::ROLE_MEMBER => 2,
        self::ROLE_LEAD   => 3,
        self::ROLE_OWNER  => 4,
    ];

    /**
     * Get the user's effective role on a project:
     *   - 'admin' for workspace admins (overrides anything)
     *   - 'owner' | 'lead' | 'member' | 'viewer' for actual project members
     *   - null when the user has no relationship to the project
     */
    public function roleFor(User $user, Project $project): ?string
    {
        if ($user->company_id !== $project->company_id) return null;
        if ($this->isWorkspaceAdmin($user))             return 'admin';

        return ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->value('role');
    }

    public function isWorkspaceAdmin(User $user): bool
    {
        return (bool) $user->isAdmin;
    }

    // ── Project-level permissions ─────────────────────────────────────────────

    public function canView(User $user, Project $project): bool
    {
        return $this->roleFor($user, $project) !== null;
    }

    public function canCreateProject(User $user): bool
    {
        // Workspace admin only - chosen policy.
        return $this->isWorkspaceAdmin($user);
    }

    public function canEditProject(User $user, Project $project): bool
    {
        return $this->hasAtLeast($user, $project, self::ROLE_LEAD);
    }

    public function canDeleteProject(User $user, Project $project): bool
    {
        return $this->hasAtLeast($user, $project, self::ROLE_OWNER);
    }

    public function canArchiveProject(User $user, Project $project): bool
    {
        return $this->hasAtLeast($user, $project, self::ROLE_OWNER);
    }

    // ── Member management ─────────────────────────────────────────────────────

    public function canManageMembers(User $user, Project $project): bool
    {
        // Both owner and lead can add/remove members. Only owner can
        // change roles though - see canChangeMemberRole().
        return $this->hasAtLeast($user, $project, self::ROLE_LEAD);
    }

    public function canChangeMemberRole(User $user, Project $project): bool
    {
        return $this->hasAtLeast($user, $project, self::ROLE_OWNER);
    }

    // ── Task permissions ──────────────────────────────────────────────────────

    /** Create new task in this project. */
    public function canCreateTask(User $user, Project $project): bool
    {
        return $this->hasAtLeast($user, $project, self::ROLE_MEMBER);
    }

    /** Update arbitrary task fields. Member can only edit own (assigned_to|created_by). */
    public function canEditTask(User $user, Project $project, Task $task): bool
    {
        $role = $this->roleFor($user, $project);
        if ($role === null) return false;
        if ($role === 'admin' || $role === self::ROLE_OWNER || $role === self::ROLE_LEAD) return true;
        if ($role === self::ROLE_VIEWER) return false;
        // role === member → only own
        return $task->assigned_to === $user->id || $task->created_by === $user->id;
    }

    public function canDeleteTask(User $user, Project $project, Task $task): bool
    {
        $role = $this->roleFor($user, $project);
        if ($role === null) return false;
        if ($role === 'admin' || $role === self::ROLE_OWNER || $role === self::ROLE_LEAD) return true;
        // creator can delete own task
        return $role === self::ROLE_MEMBER && $task->created_by === $user->id;
    }

    public function canComment(User $user, Project $project): bool
    {
        return $this->hasAtLeast($user, $project, self::ROLE_MEMBER);
    }

    public function canManageSprints(User $user, Project $project): bool
    {
        return $this->hasAtLeast($user, $project, self::ROLE_LEAD);
    }

    public function canManageLabels(User $user, Project $project): bool
    {
        return $this->hasAtLeast($user, $project, self::ROLE_LEAD);
    }

    public function canManageBudget(User $user, Project $project): bool
    {
        return $this->hasAtLeast($user, $project, self::ROLE_LEAD);
    }

    public function canApproveBudget(User $user, Project $project): bool
    {
        return $this->hasAtLeast($user, $project, self::ROLE_OWNER);
    }

    public function canUploadAttachment(User $user, Project $project): bool
    {
        return $this->hasAtLeast($user, $project, self::ROLE_MEMBER);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** True when user is admin OR project role rank ≥ required. */
    private function hasAtLeast(User $user, Project $project, string $required): bool
    {
        $role = $this->roleFor($user, $project);
        if ($role === null) return false;
        if ($role === 'admin') return true;
        return (self::RANK[$role] ?? 0) >= (self::RANK[$required] ?? 0);
    }

    /**
     * Throw 403 / 404 / 401 if the user can't perform $action on $project.
     * Use in controllers: $this->authorizer->ensure($user, $project, 'edit');
     */
    public function ensure(User $user, Project $project, string $action, ?Task $task = null): void
    {
        if ($task !== null && (int) $task->project_id !== (int) $project->id) {
            abort(404);
        }

        $ok = match ($action) {
            'view'                => $this->canView($user, $project),
            'edit'                => $this->canEditProject($user, $project),
            'delete'              => $this->canDeleteProject($user, $project),
            'archive'             => $this->canArchiveProject($user, $project),
            'manage_members'      => $this->canManageMembers($user, $project),
            'change_member_role'  => $this->canChangeMemberRole($user, $project),
            'create_task'         => $this->canCreateTask($user, $project),
            'edit_task'           => $task && $this->canEditTask($user, $project, $task),
            'delete_task'         => $task && $this->canDeleteTask($user, $project, $task),
            'comment'             => $this->canComment($user, $project),
            'manage_sprints'      => $this->canManageSprints($user, $project),
            'manage_labels'       => $this->canManageLabels($user, $project),
            'manage_budget'       => $this->canManageBudget($user, $project),
            'approve_budget'      => $this->canApproveBudget($user, $project),
            'upload_attachment'   => $this->canUploadAttachment($user, $project),
            default               => false,
        };

        if (! $ok) {
            // 403 if user can view but not act, 404 if no relationship at all
            // (don't leak existence of private projects)
            if (! $this->canView($user, $project)) {
                abort(404);
            }
            abort(403, "Anda tidak punya akses untuk aksi ini ($action).");
        }
    }

    public function ensureTaskBelongs(Project $project, Task $task): void
    {
        if ((int) $task->project_id !== (int) $project->id) {
            abort(404);
        }
    }
}
