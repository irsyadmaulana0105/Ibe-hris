<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AlignAjiEnterpriseAccess extends Command
{
    private const TARGET_EMAIL = 'aji@ibereality.com';

    private const MARKER = '20260808-aji-hr-superadmin-v1';

    private const SOURCE = 'artisan_role_align';

    private const APPLY_ACTION = 'access.aji_hr_superadmin.applied';

    private const ROLLBACK_ACTION = 'access.aji_hr_superadmin.rolled_back';

    protected $signature = 'users:align-aji-access
        {--email=aji@ibereality.com : Canonical Aji account email; alternate identities are rejected}
        {--apply : Persist the displayed plan; without this flag the command is read-only}
        {--rollback : Roll back only changes recorded by this command marker}';

    protected $description = 'Safely align Aji as primary superadmin while retaining HR roles (dry-run by default)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->option('email')));
        $apply = (bool) $this->option('apply');
        $rollback = (bool) $this->option('rollback');

        if (! hash_equals(self::TARGET_EMAIL, $email)) {
            $this->error('Precondition failed: this command is restricted to the canonical Aji identity.');

            return self::FAILURE;
        }

        if (! $this->schemaReady()) {
            $this->error('Precondition failed: required legacy, enterprise RBAC, or audit tables are missing. Run migrations first.');

            return self::FAILURE;
        }

        $canonicalUsers = User::query()
            ->withoutGlobalScopes()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->limit(2)
            ->get();

        if ($canonicalUsers->isEmpty()) {
            $this->error("Precondition failed: no user found for {$email}.");

            return self::FAILURE;
        }

        if ($canonicalUsers->count() !== 1) {
            $this->error('Precondition failed: the canonical Aji identity is ambiguous after normalized email matching. Resolve duplicates first.');

            return self::FAILURE;
        }

        /** @var User $user */
        $user = $canonicalUsers->first();

        if (! $user->company_id) {
            $this->error('Precondition failed: target user has no company_id.');

            return self::FAILURE;
        }

        if ($rollback) {
            return $this->rollback($user, $apply);
        }

        if ($user->is_inactive
            || in_array($user->employment_status, User::INACTIVE_STATUSES, true)
            || $user->resigned_at !== null) {
            $this->error('Precondition failed: resigned, terminated, or otherwise inactive identities cannot receive privileged access.');

            return self::FAILURE;
        }

        if ((string) config('enterprise_rbac.mode') !== 'shadow') {
            $this->error('Precondition failed: ENTERPRISE_RBAC_MODE must be shadow before applying or previewing this alignment.');

            return self::FAILURE;
        }

        if (! $this->hasHrIdentity($user)) {
            $this->error('Precondition failed: target is not an existing HR identity (admin role or active hr_manager assignment required).');

            return self::FAILURE;
        }

        $roleIds = $this->enterpriseRoleIds((int) $user->company_id);
        if (count($roleIds) !== 2) {
            $this->error('Precondition failed: tenant_owner and hr_manager enterprise roles must already be provisioned for the target company.');

            return self::FAILURE;
        }

        $duplicateRoleKeys = $this->duplicateActiveEnterpriseRoleKeys($user, $roleIds);
        if ($duplicateRoleKeys !== []) {
            $this->error('Precondition failed: duplicate active enterprise assignments found for '.implode(', ', $duplicateRoleKeys).'. Resolve duplicates before alignment.');

            return self::FAILURE;
        }

        $existingApply = $this->markerAudit($user, self::APPLY_ACTION);
        if ($existingApply) {
            if ($this->isDesiredState($user, $roleIds)) {
                $this->info('Already aligned by this marker; no changes were made.');

                return self::SUCCESS;
            }

            $this->error('Safety stop: this marker was applied before, but the current state has drifted. Review or roll back before another apply.');

            return self::FAILURE;
        }

        $plan = $this->buildApplyPlan($user, $roleIds);
        $this->displayApplyPlan($user, $plan, $apply);

        if (! $apply) {
            $this->comment('Dry-run only. Re-run with --apply to persist this exact alignment.');

            return self::SUCCESS;
        }

        if ($plan === []) {
            $this->info('Target already has the desired access. No marker or audit row was created because no data changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $roleIds): void {
            $canonicalUsers = User::query()
                ->withoutGlobalScopes()
                ->whereRaw('LOWER(email) = ?', [self::TARGET_EMAIL])
                ->lockForUpdate()
                ->limit(2)
                ->get();

            if ($canonicalUsers->count() !== 1
                || (int) $canonicalUsers->first()->getKey() !== (int) $user->getKey()) {
                throw new \RuntimeException('Canonical Aji identity became ambiguous before alignment; transaction rolled back.');
            }

            /** @var User $lockedUser */
            $lockedUser = $canonicalUsers->first();

            if ($lockedUser->is_inactive
                || in_array($lockedUser->employment_status, User::INACTIVE_STATUSES, true)
                || $lockedUser->resigned_at !== null) {
                throw new \RuntimeException('Target identity became inactive before alignment; transaction rolled back.');
            }

            if ($this->markerAudit($lockedUser, self::APPLY_ACTION)) {
                throw new \RuntimeException('Alignment marker was applied concurrently.');
            }

            $this->assertNoDuplicateActiveEnterpriseRoles($lockedUser, $roleIds, true);

            $mutations = [
                'user_group' => null,
                'user_roles' => [],
                'enterprise_assignments' => [],
            ];

            if ($lockedUser->group !== 'superadmin') {
                $before = $lockedUser->group;
                DB::table('users')->where('id', $lockedUser->id)->update([
                    'group' => 'superadmin',
                    'updated_at' => now(),
                ]);
                $mutations['user_group'] = [
                    'before' => $before,
                    'after' => 'superadmin',
                ];
            }

            $mutations['user_roles'] = $this->applyLegacyRoles($lockedUser);
            $mutations['enterprise_assignments'] = $this->applyEnterpriseAssignments($lockedUser, $roleIds);

            $lockedUser->refresh();
            if (! $this->isDesiredState($lockedUser, $roleIds)) {
                throw new \RuntimeException('Postcondition failed; transaction rolled back.');
            }

            $this->writeAudit($lockedUser, self::APPLY_ACTION, [
                'marker' => self::MARKER,
                'command' => $this->getName(),
                'initiated_by' => 'artisan',
                'target_email' => Str::lower($lockedUser->email),
                'mutations' => $mutations,
                'retained_enterprise_roles' => $this->activeEnterpriseRoleKeys($lockedUser),
            ]);
        });

        $this->info('Alignment applied atomically and recorded in the audit log.');

        return self::SUCCESS;
    }

    private function rollback(User $user, bool $apply): int
    {
        $appliedAudit = $this->markerAudit($user, self::APPLY_ACTION);
        if (! $appliedAudit) {
            $this->info('No applied marker exists for this user; nothing to roll back.');

            return self::SUCCESS;
        }

        if ($this->markerAudit($user, self::ROLLBACK_ACTION)) {
            $this->info('This marker was already rolled back; no changes were made.');

            return self::SUCCESS;
        }

        $meta = is_array($appliedAudit->meta) ? $appliedAudit->meta : [];
        $mutations = $meta['mutations'] ?? null;
        if (! is_array($mutations)) {
            $this->error('Safety stop: applied audit does not contain a valid mutation manifest.');

            return self::FAILURE;
        }

        $preview = $this->buildRollbackPlan($user, $mutations);
        $this->displayRollbackPlan($preview, $apply);

        if ($preview['conflicts'] !== []) {
            $this->error('Safety stop: rollback found state drift. No data was changed.');
            foreach ($preview['conflicts'] as $conflict) {
                $this->line(" - {$conflict}");
            }

            return self::FAILURE;
        }

        if (! $apply) {
            $this->comment('Rollback dry-run only. Re-run with --rollback --apply to execute.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $appliedAudit, $mutations): void {
            $canonicalUsers = User::query()
                ->withoutGlobalScopes()
                ->whereRaw('LOWER(email) = ?', [self::TARGET_EMAIL])
                ->lockForUpdate()
                ->limit(2)
                ->get();

            if ($canonicalUsers->count() !== 1
                || (int) $canonicalUsers->first()->getKey() !== (int) $user->getKey()) {
                throw new \RuntimeException('Canonical Aji identity became ambiguous before rollback; transaction rolled back.');
            }

            /** @var User $lockedUser */
            $lockedUser = $canonicalUsers->first();

            $plan = $this->buildRollbackPlan($lockedUser, $mutations, true);
            if ($plan['conflicts'] !== []) {
                throw new \RuntimeException('Rollback state changed concurrently; transaction rolled back.');
            }

            foreach ($plan['actions'] as $action) {
                match ($action['type']) {
                    'restore_group' => DB::table('users')->where('id', $lockedUser->id)->update([
                        'group' => $action['value'],
                        'updated_at' => now(),
                    ]),
                    'restore_user_role' => DB::table('user_roles')->where('id', $action['id'])->update([
                        'is_primary' => $action['is_primary'],
                        'updated_at' => now(),
                    ]),
                    'delete_user_role' => DB::table('user_roles')->where('id', $action['id'])->delete(),
                    'revoke_enterprise_assignment' => DB::table('rbac_user_assignments')->where('id', $action['id'])->update([
                        'status' => 'revoked',
                        'valid_until' => now(),
                        'updated_at' => now(),
                    ]),
                    default => null,
                };
            }

            $lockedUser->refresh();
            $this->writeAudit($lockedUser, self::ROLLBACK_ACTION, [
                'marker' => self::MARKER,
                'command' => $this->getName(),
                'initiated_by' => 'artisan',
                'target_email' => Str::lower($lockedUser->email),
                'applied_audit_id' => $appliedAudit->id,
                'actions' => $plan['actions'],
            ]);
        });

        $this->info('Marker-scoped rollback completed atomically. Unrelated roles and assignments were retained.');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function enterpriseRoleIds(int $companyId): array
    {
        return DB::table('rbac_roles')
            ->where('company_id', $companyId)
            ->whereIn('key', ['tenant_owner', 'hr_manager'])
            ->pluck('id', 'key')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function hasHrIdentity(User $user): bool
    {
        if ($user->group === 'admin') {
            return true;
        }

        if (DB::table('user_roles')->where('user_id', $user->id)->where('role', 'admin')->exists()) {
            return true;
        }

        return in_array('hr_manager', $this->activeEnterpriseRoleKeys($user), true);
    }

    /** @param array<string, int> $roleIds */
    private function isDesiredState(User $user, array $roleIds): bool
    {
        if ($user->group !== 'superadmin') {
            return false;
        }

        $legacyRoles = DB::table('user_roles')
            ->where('user_id', $user->id)
            ->get(['role', 'is_primary'])
            ->keyBy('role');

        if (! $legacyRoles->has('superadmin') || ! (bool) $legacyRoles['superadmin']->is_primary) {
            return false;
        }
        if (! $legacyRoles->has('admin') || (bool) $legacyRoles['admin']->is_primary) {
            return false;
        }
        if ($legacyRoles->contains(fn ($row) => $row->role !== 'superadmin' && (bool) $row->is_primary)) {
            return false;
        }

        $activeRoleCounts = $this->activeEnterpriseRoleCounts($user, $roleIds);

        return $activeRoleCounts['tenant_owner'] === 1
            && $activeRoleCounts['hr_manager'] === 1;
    }

    /** @param array<string, int> $roleIds */
    private function buildApplyPlan(User $user, array $roleIds): array
    {
        $plan = [];

        if ($user->group !== 'superadmin') {
            $plan[] = ['Layer' => 'users', 'Current' => $user->group, 'Desired' => 'superadmin', 'Action' => 'update primary group'];
        }

        $roles = DB::table('user_roles')->where('user_id', $user->id)->get()->keyBy('role');
        foreach (['superadmin' => true, 'admin' => false] as $role => $primary) {
            $row = $roles->get($role);
            if (! $row) {
                $plan[] = ['Layer' => 'user_roles', 'Current' => "{$role}: missing", 'Desired' => "{$role}: ".($primary ? 'primary' : 'additional'), 'Action' => 'create marked row'];
            } elseif ((bool) $row->is_primary !== $primary) {
                $plan[] = ['Layer' => 'user_roles', 'Current' => "{$role}: ".((bool) $row->is_primary ? 'primary' : 'additional'), 'Desired' => "{$role}: ".($primary ? 'primary' : 'additional'), 'Action' => 'normalize primary flag'];
            }
        }

        foreach ($roles as $role => $row) {
            if (! in_array($role, ['superadmin', 'admin'], true) && (bool) $row->is_primary) {
                $plan[] = ['Layer' => 'user_roles', 'Current' => "{$role}: primary", 'Desired' => "{$role}: additional", 'Action' => 'normalize primary flag'];
            }
        }

        $activeRoleIds = $this->activeEnterpriseAssignments($user)->pluck('role_id')->map(fn ($id) => (int) $id);
        foreach (['tenant_owner', 'hr_manager'] as $role) {
            if (! $activeRoleIds->contains($roleIds[$role])) {
                $plan[] = ['Layer' => 'enterprise', 'Current' => "{$role}: missing/inactive", 'Desired' => "{$role}: active", 'Action' => 'create marked assignment'];
            }
        }

        return $plan;
    }

    private function displayApplyPlan(User $user, array $plan, bool $apply): void
    {
        $this->info(($apply ? 'APPLY' : 'DRY RUN').' - Aji HR + Superadmin alignment');
        $this->line("Target: {$user->email} ({$user->id})");
        $this->line('Marker: '.self::MARKER);
        if ($plan === []) {
            $this->info('No changes required.');

            return;
        }

        $this->table(['Layer', 'Current', 'Desired', 'Action'], $plan);
    }

    /** @return array<int, array<string, mixed>> */
    private function applyLegacyRoles(User $user): array
    {
        $mutations = [];
        $rows = DB::table('user_roles')->where('user_id', $user->id)->lockForUpdate()->get()->keyBy('role');

        foreach (['superadmin' => true, 'admin' => false] as $role => $desiredPrimary) {
            $row = $rows->get($role);
            if (! $row) {
                $grantedAt = now();
                $notes = '['.self::MARKER.'] Managed role alignment';
                $id = DB::table('user_roles')->insertGetId([
                    'user_id' => $user->id,
                    'role' => $role,
                    'is_primary' => $desiredPrimary,
                    'granted_by' => null,
                    'notes' => $notes,
                    'granted_at' => $grantedAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $mutations[] = [
                    'operation' => 'created',
                    'id' => $id,
                    'role' => $role,
                    'after' => [
                        'is_primary' => $desiredPrimary,
                        'granted_by' => null,
                        'notes' => $notes,
                        'notes_marker' => self::MARKER,
                        'granted_at' => $grantedAt->format('Y-m-d H:i:s'),
                    ],
                ];
            } elseif ((bool) $row->is_primary !== $desiredPrimary) {
                DB::table('user_roles')->where('id', $row->id)->update([
                    'is_primary' => $desiredPrimary,
                    'updated_at' => now(),
                ]);
                $mutations[] = [
                    'operation' => 'updated',
                    'id' => $row->id,
                    'role' => $role,
                    'before' => ['is_primary' => (bool) $row->is_primary],
                    'after' => ['is_primary' => $desiredPrimary],
                ];
            }
        }

        foreach ($rows as $role => $row) {
            if ($role === 'superadmin' || ! (bool) $row->is_primary) {
                continue;
            }

            // The admin row was already normalized above.
            if (collect($mutations)->contains(fn ($mutation) => $mutation['operation'] === 'updated' && (int) $mutation['id'] === (int) $row->id)) {
                continue;
            }

            DB::table('user_roles')->where('id', $row->id)->update([
                'is_primary' => false,
                'updated_at' => now(),
            ]);
            $mutations[] = [
                'operation' => 'updated',
                'id' => $row->id,
                'role' => $role,
                'before' => ['is_primary' => true],
                'after' => ['is_primary' => false],
            ];
        }

        return $mutations;
    }

    /** @param array<string, int> $roleIds
     * @return array<int, array<string, mixed>>
     */
    private function applyEnterpriseAssignments(User $user, array $roleIds): array
    {
        $mutations = [];
        $activeRoleIds = $this->activeEnterpriseAssignments($user, true)
            ->pluck('role_id')
            ->map(fn ($id) => (int) $id);

        foreach (['tenant_owner', 'hr_manager'] as $roleKey) {
            $roleId = $roleIds[$roleKey];
            if ($activeRoleIds->contains($roleId)) {
                continue;
            }

            $id = (string) Str::ulid();
            $reason = '['.self::MARKER."] Retain {$roleKey} enterprise access";
            $validFrom = now();
            DB::table('rbac_user_assignments')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'role_id' => $roleId,
                'company_id' => $user->company_id,
                'status' => 'active',
                'valid_from' => $validFrom,
                'valid_until' => null,
                'approval_limit' => null,
                'granted_by' => null,
                'reason' => $reason,
                'source' => self::SOURCE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $mutations[] = [
                'operation' => 'created',
                'id' => $id,
                'role_key' => $roleKey,
                'role_id' => $roleId,
                'after' => [
                    'status' => 'active',
                    'source' => self::SOURCE,
                    'reason' => $reason,
                    'valid_from' => $validFrom->format('Y-m-d H:i:s'),
                    'valid_until' => null,
                    'approval_limit' => null,
                    'granted_by' => null,
                ],
            ];
        }

        return $mutations;
    }

    private function activeEnterpriseAssignments(User $user, bool $lock = false)
    {
        $now = now();
        $query = DB::table('rbac_user_assignments')
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('status', 'active')
            ->where(fn ($builder) => $builder->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn ($builder) => $builder->whereNull('valid_until')->orWhere('valid_until', '>=', $now));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** @param array<string, int> $roleIds
     * @return array{tenant_owner: int, hr_manager: int}
     */
    private function activeEnterpriseRoleCounts(User $user, array $roleIds, bool $lock = false): array
    {
        $countsById = $this->activeEnterpriseAssignments($user, $lock)
            ->countBy(fn ($assignment) => (int) $assignment->role_id);

        return [
            'tenant_owner' => (int) $countsById->get($roleIds['tenant_owner'], 0),
            'hr_manager' => (int) $countsById->get($roleIds['hr_manager'], 0),
        ];
    }

    /** @param array<string, int> $roleIds
     * @return array<int, string>
     */
    private function duplicateActiveEnterpriseRoleKeys(User $user, array $roleIds, bool $lock = false): array
    {
        return collect($this->activeEnterpriseRoleCounts($user, $roleIds, $lock))
            ->filter(fn (int $count) => $count > 1)
            ->keys()
            ->values()
            ->all();
    }

    /** @param array<string, int> $roleIds */
    private function assertNoDuplicateActiveEnterpriseRoles(User $user, array $roleIds, bool $lock = false): void
    {
        $duplicates = $this->duplicateActiveEnterpriseRoleKeys($user, $roleIds, $lock);
        if ($duplicates !== []) {
            throw new \RuntimeException('Duplicate active enterprise assignments detected for '.implode(', ', $duplicates).'.');
        }
    }

    /** @return array<int, string> */
    private function activeEnterpriseRoleKeys(User $user): array
    {
        $ids = $this->activeEnterpriseAssignments($user)->pluck('role_id');
        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('rbac_roles')->whereIn('id', $ids)->orderBy('key')->pluck('key')->all();
    }

    /** @return array{actions: array<int, array<string, mixed>>, conflicts: array<int, string>, already_reverted: array<int, string>} */
    private function buildRollbackPlan(User $user, array $mutations, bool $lock = false): array
    {
        $plan = ['actions' => [], 'conflicts' => [], 'already_reverted' => []];

        $group = $mutations['user_group'] ?? null;
        if (is_array($group)) {
            if ($user->group === ($group['before'] ?? null)) {
                $plan['already_reverted'][] = 'users.group';
            } elseif ($user->group === ($group['after'] ?? null)) {
                $plan['actions'][] = ['type' => 'restore_group', 'value' => $group['before']];
            } else {
                $plan['conflicts'][] = "users.group is {$user->group}, expected {$group['after']} before rollback";
            }
        }

        foreach ($mutations['user_roles'] ?? [] as $mutation) {
            $query = DB::table('user_roles')->where('id', $mutation['id']);
            if ($lock) {
                $query->lockForUpdate();
            }
            $row = $query->first();

            if (($mutation['operation'] ?? null) === 'created') {
                if (! $row) {
                    $plan['already_reverted'][] = "user_roles.{$mutation['role']}";

                    continue;
                }
                $notesMarker = (string) ($mutation['after']['notes_marker'] ?? '');
                $marked = hash_equals(self::MARKER, $notesMarker)
                    && Str::contains((string) $row->notes, $notesMarker);
                $grantedAtMatches = $this->timestampsEqual(
                    $row->granted_at,
                    $mutation['after']['granted_at'] ?? null,
                );
                if ((string) $row->user_id !== (string) $user->id
                    || $row->role !== $mutation['role']
                    || (bool) $row->is_primary !== (bool) $mutation['after']['is_primary']
                    || $row->granted_by !== ($mutation['after']['granted_by'] ?? null)
                    || $row->notes !== ($mutation['after']['notes'] ?? null)
                    || ! $grantedAtMatches
                    || ! $marked) {
                    $plan['conflicts'][] = "created user_roles.{$mutation['role']} no longer matches the marker manifest";

                    continue;
                }
                $plan['actions'][] = ['type' => 'delete_user_role', 'id' => $row->id, 'role' => $row->role];

                continue;
            }

            if (! $row || (string) $row->user_id !== (string) $user->id || $row->role !== $mutation['role']) {
                $plan['conflicts'][] = "updated user_roles.{$mutation['role']} is missing or belongs to another identity";

                continue;
            }

            $before = (bool) $mutation['before']['is_primary'];
            $after = (bool) $mutation['after']['is_primary'];
            if ((bool) $row->is_primary === $before) {
                $plan['already_reverted'][] = "user_roles.{$mutation['role']}";
            } elseif ((bool) $row->is_primary === $after) {
                $plan['actions'][] = [
                    'type' => 'restore_user_role',
                    'id' => $row->id,
                    'role' => $row->role,
                    'is_primary' => $before,
                ];
            } else {
                $plan['conflicts'][] = "updated user_roles.{$mutation['role']} has an unexpected primary flag";
            }
        }

        foreach ($mutations['enterprise_assignments'] ?? [] as $mutation) {
            $query = DB::table('rbac_user_assignments')->where('id', $mutation['id']);
            if ($lock) {
                $query->lockForUpdate();
            }
            $row = $query->first();
            if (! $row) {
                $plan['conflicts'][] = "enterprise.{$mutation['role_key']} marker-created assignment is missing";

                continue;
            }

            $expected = $mutation['after'];
            $hasScopes = DB::table('rbac_assignment_scopes')
                ->where('assignment_id', $row->id)
                ->exists();
            $baseMatches = (string) $row->user_id === (string) $user->id
                && (int) $row->company_id === (int) $user->company_id
                && (int) $row->role_id === (int) $mutation['role_id']
                && $row->source === $expected['source']
                && $row->reason === $expected['reason']
                && $this->timestampsEqual($row->valid_from, $expected['valid_from'] ?? null)
                && $row->approval_limit === ($expected['approval_limit'] ?? null)
                && $row->granted_by === ($expected['granted_by'] ?? null)
                && ! $hasScopes
                && Str::contains($row->reason, self::MARKER);

            if (! $baseMatches) {
                $plan['conflicts'][] = "enterprise.{$mutation['role_key']} no longer matches the marker manifest";

                continue;
            }

            if ($row->status === 'revoked' && $row->valid_until !== null) {
                $plan['already_reverted'][] = "enterprise.{$mutation['role_key']}";

                continue;
            }

            if ($row->status !== $expected['status']
                || $row->valid_until !== ($expected['valid_until'] ?? null)) {
                $plan['conflicts'][] = "enterprise.{$mutation['role_key']} has an unexpected lifecycle state";

                continue;
            }

            $plan['actions'][] = [
                'type' => 'revoke_enterprise_assignment',
                'id' => $row->id,
                'role_key' => $mutation['role_key'],
            ];
        }

        return $plan;
    }

    private function displayRollbackPlan(array $plan, bool $apply): void
    {
        $this->info(($apply ? 'ROLLBACK APPLY' : 'ROLLBACK DRY RUN').' - marker '.self::MARKER);
        $rows = collect($plan['actions'])->map(fn ($action) => [
            'Action' => $action['type'],
            'Target' => $action['role'] ?? $action['role_key'] ?? 'users.group',
        ])->all();
        if ($rows !== []) {
            $this->table(['Action', 'Target'], $rows);
        } else {
            $this->info('No rollback mutations are currently required.');
        }
    }

    private function timestampsEqual(mixed $current, mixed $expected): bool
    {
        if ($current === null || $expected === null) {
            return $current === null && $expected === null;
        }

        return Carbon::parse($current)->equalTo(Carbon::parse($expected));
    }

    private function markerAudit(User $user, string $action): ?AuditLog
    {
        return AuditLog::query()
            ->withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('action', $action)
            ->where('entity_type', 'user_access_alignment')
            ->where('entity_id', (string) $user->id)
            ->orderByDesc('id')
            ->get()
            ->first(fn (AuditLog $log) => data_get($log->meta, 'marker') === self::MARKER);
    }

    private function writeAudit(User $user, string $action, array $meta): void
    {
        AuditLog::query()->withoutGlobalScopes()->create([
            'company_id' => $user->company_id,
            'actor_id' => null,
            'action' => $action,
            'entity_type' => 'user_access_alignment',
            'entity_id' => (string) $user->id,
            'meta' => $meta,
            'ip_address' => null,
            'user_agent' => 'artisan:'.$this->getName(),
        ]);
    }

    private function schemaReady(): bool
    {
        return collect([
            'users',
            'user_roles',
            'rbac_roles',
            'rbac_user_assignments',
            'rbac_assignment_scopes',
            'audit_logs',
        ])->every(fn (string $table) => Schema::hasTable($table));
    }
}
