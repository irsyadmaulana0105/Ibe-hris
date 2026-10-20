<?php

namespace App\Services;

use App\Models\RbacAccessDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnterpriseAuthorizationService
{
    /** @var array<string, array{allowed: bool, reason: string}> */
    private array $targetCache = [];

    private ?bool $schemaAvailable = null;

    public function allows(User $user, string $capability, array $context = []): bool
    {
        $legacy = $user->legacyHasCapability($capability);
        $mode = (string) config('enterprise_rbac.mode', 'shadow');

        if ($mode === 'legacy') {
            return $legacy;
        }

        // Shadow rollout remains backward compatible while an enforce rollout
        // must fail closed if any policy table is unavailable. Falling back to
        // broad legacy groups during an outage would silently elevate access.
        if (! $this->schemaReady()) {
            return $mode === 'enforce' ? false : $legacy;
        }

        $target = $this->targetDecision($user, $capability, $context);
        $effective = $mode === 'enforce' ? $target['allowed'] : $legacy;

        $this->recordDecision($user, $capability, $context, $mode, $legacy, $target, $effective);

        return $effective;
    }

    /** @return array{allowed: bool, reason: string} */
    public function targetDecision(User $user, string $capability, array $context = []): array
    {
        if ($user->is_inactive) {
            return ['allowed' => false, 'reason' => 'identity_inactive'];
        }

        if ((int) $user->company_id <= 0) {
            return ['allowed' => false, 'reason' => 'tenant_missing'];
        }

        if (isset($context['company_id']) && (int) $context['company_id'] !== (int) $user->company_id) {
            return ['allowed' => false, 'reason' => 'tenant_mismatch'];
        }

        if (($context['irreversible'] ?? false) && isset($context['maker_id']) && (string) $context['maker_id'] === (string) $user->id) {
            return ['allowed' => false, 'reason' => 'sod_self_approval'];
        }

        $cacheKey = implode('|', [
            $user->id,
            $capability,
            hash('sha256', json_encode($this->safeContext($context))),
        ]);
        if (isset($this->targetCache[$cacheKey])) {
            return $this->targetCache[$cacheKey];
        }

        $now = now();
        $assignments = DB::table('rbac_user_assignments as a')
            ->join('rbac_roles as r', 'r.id', '=', 'a.role_id')
            ->join('rbac_role_capability as rc', 'rc.role_id', '=', 'r.id')
            ->join('rbac_capabilities as c', 'c.id', '=', 'rc.capability_id')
            ->where('a.user_id', $user->id)
            ->where('a.company_id', $user->company_id)
            ->where('r.company_id', $user->company_id)
            ->where('c.company_id', $user->company_id)
            ->where('a.status', 'active')
            ->where('c.capability_key', $capability)
            ->where(fn ($q) => $q->whereNull('a.valid_from')->orWhere('a.valid_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('a.valid_until')->orWhere('a.valid_until', '>=', $now))
            ->select(['a.id', 'a.approval_limit'])
            ->get();

        foreach ($assignments as $assignment) {
            if (! $this->amountWithinLimit($assignment->approval_limit, $context)) {
                continue;
            }
            if (! $this->scopeMatches((string) $assignment->id, $context)) {
                continue;
            }

            return $this->targetCache[$cacheKey] = ['allowed' => true, 'reason' => 'active_assignment'];
        }

        $directDelegations = DB::table('rbac_delegations as d')
            ->join('rbac_capabilities as c', function ($join) {
                $join->on('c.capability_key', '=', 'd.capability_key')
                    ->on('c.company_id', '=', 'd.company_id');
            })
            ->where('d.company_id', $user->company_id)
            ->where('d.delegate_id', $user->id)
            ->whereNull('d.role_id')
            ->where('d.capability_key', $capability)
            ->where('c.company_id', $user->company_id)
            ->where('d.status', 'active')
            ->where('d.valid_from', '<=', $now)
            ->where('d.valid_until', '>=', $now)
            ->select(['d.id', 'd.approval_limit'])
            ->get();

        $roleDelegations = DB::table('rbac_delegations as d')
            ->join('rbac_roles as r', function ($join) {
                $join->on('r.id', '=', 'd.role_id')
                    ->on('r.company_id', '=', 'd.company_id');
            })
            ->join('rbac_role_capability as rc', 'rc.role_id', '=', 'r.id')
            ->join('rbac_capabilities as c', function ($join) {
                $join->on('c.id', '=', 'rc.capability_id')
                    ->on('c.company_id', '=', 'd.company_id');
            })
            ->where('d.company_id', $user->company_id)
            ->where('d.delegate_id', $user->id)
            ->whereNull('d.capability_key')
            ->where('r.company_id', $user->company_id)
            ->where('c.company_id', $user->company_id)
            ->where('c.capability_key', $capability)
            ->where('d.status', 'active')
            ->where('d.valid_from', '<=', $now)
            ->where('d.valid_until', '>=', $now)
            ->select(['d.id', 'd.approval_limit'])
            ->get();

        $delegated = $directDelegations
            ->concat($roleDelegations)
            ->contains(fn ($row) => $this->amountWithinLimit($row->approval_limit, $context));

        return $this->targetCache[$cacheKey] = $delegated
            ? ['allowed' => true, 'reason' => 'active_delegation']
            : ['allowed' => false, 'reason' => $assignments->isNotEmpty() ? 'condition_failed' : 'capability_not_granted'];
    }

    private function scopeMatches(string $assignmentId, array $context): bool
    {
        $scopes = DB::table('rbac_assignment_scopes')
            ->where('assignment_id', $assignmentId)
            ->get(['scope_type', 'scope_id']);

        if ($scopes->isEmpty()) {
            return true;
        }

        $requested = $context['scopes'] ?? [];
        foreach ($scopes as $scope) {
            $candidate = $requested[$scope->scope_type] ?? null;
            if ($candidate !== null && (string) $candidate === (string) $scope->scope_id) {
                return true;
            }
        }

        return false;
    }

    private function amountWithinLimit(mixed $limit, array $context): bool
    {
        if ($limit === null || ! isset($context['amount'])) {
            return true;
        }

        return (float) $context['amount'] <= (float) $limit;
    }

    private function schemaReady(): bool
    {
        return $this->schemaAvailable ??= Schema::hasTable('rbac_user_assignments')
            && Schema::hasTable('rbac_roles')
            && Schema::hasTable('rbac_capabilities')
            && Schema::hasTable('rbac_role_capability')
            && Schema::hasTable('rbac_assignment_scopes')
            && Schema::hasTable('rbac_delegations');
    }

    /** Never persist request bodies or PII in access-decision context. */
    private function safeContext(array $context): array
    {
        return array_intersect_key($context, array_flip([
            'company_id', 'resource_type', 'resource_id', 'amount', 'maker_id',
            'irreversible', 'action', 'status', 'scopes',
        ]));
    }

    /** @param array{allowed: bool, reason: string} $target */
    private function recordDecision(
        User $user,
        string $capability,
        array $context,
        string $mode,
        bool $legacy,
        array $target,
        bool $effective,
    ): void {
        if (! config('enterprise_rbac.record_decisions', false)) {
            return;
        }
        if ($legacy === $target['allowed'] && ! config('enterprise_rbac.record_matches', false)) {
            return;
        }
        if (! Schema::hasTable('rbac_access_decisions')) {
            return;
        }

        RbacAccessDecision::create([
            'company_id' => $user->company_id,
            'actor_id' => $user->id,
            'capability_key' => $capability,
            'resource_type' => $context['resource_type'] ?? null,
            'resource_id' => isset($context['resource_id']) ? (string) $context['resource_id'] : null,
            'mode' => $mode,
            'legacy_allowed' => $legacy,
            'target_allowed' => $target['allowed'],
            'effective_allowed' => $effective,
            'reason_code' => $target['reason'],
            'context' => $this->safeContext($context),
            'created_at' => now(),
        ]);
    }
}
