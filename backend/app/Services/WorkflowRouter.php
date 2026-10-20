<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use Illuminate\Support\Collection;

/**
 * Resolves the next approval step for a given workflow key (leave / overtime /
 * reimburse / kasbon) and validates that a given user is authorized to take
 * the current step. Replaces hardcoded single-level approval routing.
 *
 * The Service is consulted by *Service classes (LeaveService::approve etc.)
 * before transitioning status. If no workflow steps are defined, falls back
 * to single-step behavior (current default for ≤50-user companies).
 */
class WorkflowRouter
{
    /**
     * Find the step the request is currently waiting on.
     *
     * @param int $companyId
     * @param string $workflowKey "leave" | "overtime" | "reimburse" | "kasbon"
     * @param int|null $currentStepSortOrder null = start of flow
     * @return WorkflowStep|null next step, or null if workflow finalized
     */
    public function nextStep(int $companyId, string $workflowKey, ?int $currentStepSortOrder = null): ?WorkflowStep
    {
        $def = WorkflowDefinition::query()
            ->where('company_id', $companyId)
            ->where('key', $workflowKey)
            ->with('steps')
            ->first();

        if (!$def || $def->steps->isEmpty()) {
            return null;
        }

        $steps = $def->steps->sortBy('sort_order')->values();

        // Skip the first "submit" step (employee-side)
        $approvableSteps = $steps->filter(fn (WorkflowStep $s) => $s->role !== 'submit')->values();

        if ($approvableSteps->isEmpty()) {
            return null;
        }

        // Find next step after current sort_order
        $next = $approvableSteps->first(fn (WorkflowStep $s) =>
            $currentStepSortOrder === null || $s->sort_order > $currentStepSortOrder
        );

        return $next;
    }

    /**
     * Check whether a user is authorized to approve the current step based on
     * their group/capability. Mapping is conservative: maps step.role to
     * required Laravel capability.
     */
    public function userCanAct(User $user, WorkflowStep $step, string $workflowKey): bool
    {
        // Superadmin bypasses everything
        if (method_exists($user, 'hasCapability') && $user->hasCapability('rbac.manage')) {
            return true;
        }

        $required = match ($step->role) {
            'approve', 'verify', 'review', 'finalize' => match ($workflowKey) {
                'leave'      => 'leave.manage',
                'overtime'   => 'overtime.manage',
                'reimburse'  => 'reimbursement.manage',
                'kasbon'     => 'salary_advance.manage',
                'payroll'    => 'payroll.approve',
                default      => 'admin.access',
            },
            'pay' => match ($workflowKey) {
                'reimburse' => 'reimbursement.manage',
                'kasbon'    => 'salary_advance.manage',
                'payroll'   => 'payroll.pay',
                default     => 'admin.access',
            },
            default => 'admin.access',
        };

        return method_exists($user, 'hasCapability') && $user->hasCapability($required);
    }

    /**
     * Summarize the full workflow path for UI display ("Step X of Y: <label>").
     */
    public function describe(int $companyId, string $workflowKey): array
    {
        $def = WorkflowDefinition::query()
            ->where('company_id', $companyId)
            ->where('key', $workflowKey)
            ->with('steps')
            ->first();

        if (!$def) return ['steps' => [], 'total' => 0];

        return [
            'name'  => $def->name,
            'steps' => $def->steps->sortBy('sort_order')->values()->map(fn (WorkflowStep $s, $i) => [
                'index'      => $i + 1,
                'label'      => $s->label,
                'role'       => $s->role,
                'color'      => $s->color,
                'sort_order' => $s->sort_order,
            ])->all(),
            'total' => $def->steps->count(),
        ];
    }
}
