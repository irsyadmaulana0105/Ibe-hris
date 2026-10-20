<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\ProjectAuthorizer;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectMemberController extends ApiController
{
    public function __construct(
        private readonly ProjectService $service,
        private readonly ProjectAuthorizer $authorizer,
    ) {}

    /**
     * GET /api/v1/projects/{project}/members
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'view');

        $members = $project->members()
            ->with('user:id,name,email,profile_photo_path,employment_status,resigned_at,last_working_date')
            ->get();

        return $this->success($members);
    }

    /**
     * GET /api/v1/projects/{project}/members/available
     * List users in the same company who are not yet members
     */
    public function available(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'view');

        $existingIds = $project->members()->pluck('user_id');

        $q = $request->query('q');

        $users = User::query()
            ->withoutGlobalScopes()
            ->where('company_id', $project->company_id)
            ->whereNotIn('id', $existingIds)
            ->whereNotIn('employment_status', User::INACTIVE_STATUSES)
            ->whereNull('resigned_at')
            ->when($q, function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                });
            })
            ->select('id', 'name', 'email', 'profile_photo_path', 'employment_status', 'resigned_at', 'last_working_date')
            ->orderBy('name')
            ->limit(20)
            ->get();

        return $this->success($users);
    }

    /**
     * POST /api/v1/projects/{project}/members
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_members');

        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'role' => ['nullable', Rule::in(['member', 'lead', 'owner', 'viewer'])],
        ]);

        // Enforce same-company membership
        $candidate = User::query()
            ->withoutGlobalScopes()
            ->where('id', $data['user_id'])
            ->first();

        if (! $candidate || $candidate->company_id !== $project->company_id) {
            abort(422, 'User tidak berada dalam company yang sama.');
        }

        if ($candidate->is_inactive) {
            abort(422, 'Karyawan sudah resign atau dinonaktifkan di ERP dan tidak dapat ditambahkan ke proyek.');
        }

        $existing = $project->members()->where('user_id', $data['user_id'])->first();
        if ($existing) {
            abort(422, 'User sudah menjadi member projek.');
        }

        $member = $project->members()->create([
            'user_id' => $data['user_id'],
            'role' => $data['role'] ?? 'member',
        ]);

        $member->load('user:id,name,email,profile_photo_path,employment_status,resigned_at,last_working_date');

        return $this->created($member, 'Member ditambahkan.');
    }

    /**
     * PUT /api/v1/projects/{project}/members/{member}
     */
    public function update(Request $request, Project $project, ProjectMember $member): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'change_member_role');
        $this->ensureBelongs($member, $project);

        $data = $request->validate([
            'role' => ['required', Rule::in(['member', 'lead', 'owner', 'viewer'])],
        ]);

        $member->update(['role' => $data['role']]);
        $member->load('user:id,name,email,profile_photo_path,employment_status,resigned_at,last_working_date');

        return $this->success($member);
    }

    /**
     * DELETE /api/v1/projects/{project}/members/{member}
     */
    public function destroy(Request $request, Project $project, ProjectMember $member): JsonResponse
    {
        $this->authorizer->ensure($request->user(), $project, 'manage_members');
        $this->ensureBelongs($member, $project);

        // Don't allow removing the last owner
        if ($member->role === 'owner') {
            $ownerCount = $project->members()->where('role', 'owner')->count();
            if ($ownerCount <= 1) {
                abort(422, 'Tidak dapat menghapus owner terakhir.');
            }
        }

        $member->delete();

        return $this->success(message: 'Member dihapus.');
    }

    private function ensureBelongs(ProjectMember $member, Project $project): void
    {
        if ($member->project_id !== $project->id) {
            abort(404);
        }
    }
}
