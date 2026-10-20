<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectBudgetAudit;
use App\Models\ProjectBudgetItem;
use App\Models\ProjectBudgetVersion;
use App\Models\ProjectProgressEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectBudgetService
{
    public function detail(Project $project): array
    {
        $budget = ProjectBudget::withoutGlobalScopes()
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->with(['items.progressEntries', 'versions.approver:id,name', 'audits.actor:id,name'])
            ->first();

        if (! $budget) {
            return ['budget' => null, 'items' => [], 'summary' => $this->summary(collect(), 0, 0), 'curve' => [], 'versions' => [], 'audit' => []];
        }

        return [
            'budget' => $budget->makeHidden('items'),
            'items' => $budget->items,
            'summary' => $this->summary($budget->items, (float) $budget->tax_percent, (float) $budget->contingency_percent),
            'curve' => $this->curve($budget->items),
            'versions' => $budget->versions,
            'audit' => $budget->audits->take(50)->values(),
        ];
    }

    public function saveSettings(Project $project, array $data, User $user): ProjectBudget
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $budget = ProjectBudget::withoutGlobalScopes()->firstOrNew([
                'company_id' => $project->company_id,
                'project_id' => $project->id,
            ]);
            $before = $budget->exists ? $budget->toArray() : null;
            $budget->fill($data);
            $budget->save();
            $this->audit($budget, 'settings_updated', $user, $before, $budget->fresh()->toArray());

            return $budget;
        });
    }

    public function addItem(Project $project, array $data, User $user): ProjectBudgetItem
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $budget = ProjectBudget::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $project->company_id, 'project_id' => $project->id],
                ['currency' => 'IDR', 'status' => 'draft', 'tax_percent' => 0, 'contingency_percent' => 0]
            );
            $this->ensureEditable($budget);
            if (! isset($data['sort_order'])) {
                $data['sort_order'] = (int) ProjectBudgetItem::withoutGlobalScopes()->where('project_budget_id', $budget->id)->max('sort_order') + 1;
            }
            $item = ProjectBudgetItem::withoutGlobalScopes()->create($data + [
                'company_id' => $project->company_id,
                'project_budget_id' => $budget->id,
            ]);
            $this->audit($budget, 'item_created', $user, null, $item->toArray(), $item);

            return $item;
        });
    }

    public function updateItem(Project $project, ProjectBudgetItem $item, array $data, User $user): ProjectBudgetItem
    {
        $this->assertItem($project, $item);
        $budget = ProjectBudget::withoutGlobalScopes()->findOrFail($item->project_budget_id);
        $this->ensureEditable($budget);
        $before = $item->toArray();
        $item->update($data);
        $this->audit($budget, 'item_updated', $user, $before, $item->fresh()->toArray(), $item);

        return $item->refresh();
    }

    public function deleteItem(Project $project, ProjectBudgetItem $item, User $user): void
    {
        $this->assertItem($project, $item);
        $budget = ProjectBudget::withoutGlobalScopes()->findOrFail($item->project_budget_id);
        $this->ensureEditable($budget);
        $before = $item->toArray();
        $item->delete();
        $this->audit($budget, 'item_deleted', $user, $before, null, $item);
    }

    public function saveProgress(Project $project, ProjectBudgetItem $item, array $data, User $user): ProjectProgressEntry
    {
        $this->assertItem($project, $item);
        $previous = ProjectProgressEntry::withoutGlobalScopes()
            ->where('company_id', $project->company_id)->where('budget_item_id', $item->id)
            ->where('entry_date', '<', $data['entry_date'])->latest('entry_date')->first();
        if ($previous && ((float) $data['planned_percent'] < (float) $previous->planned_percent || (float) $data['actual_percent'] < (float) $previous->actual_percent)) {
            throw ValidationException::withMessages(['actual_percent' => 'Progres kumulatif tidak boleh lebih rendah dari catatan sebelumnya.']);
        }
        $entry = ProjectProgressEntry::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $project->company_id, 'budget_item_id' => $item->id, 'entry_date' => $data['entry_date']],
            $data + ['project_id' => $project->id, 'created_by' => $user->id]
        );
        $this->audit(ProjectBudget::withoutGlobalScopes()->findOrFail($item->project_budget_id), 'progress_recorded', $user, null, $entry->toArray(), $entry);

        return $entry;
    }

    public function submit(Project $project, User $user, ?string $reason): ProjectBudget
    {
        $budget = ProjectBudget::withoutGlobalScopes()->where('project_id', $project->id)->where('company_id', $project->company_id)->firstOrFail();
        if (! in_array($budget->status, ['draft', 'rejected'], true) || ! ProjectBudgetItem::withoutGlobalScopes()->where('project_budget_id', $budget->id)->exists()) {
            throw ValidationException::withMessages(['status' => 'RAB hanya dapat diajukan dari draft/rejected dan harus memiliki item.']);
        }
        $before = $budget->toArray();
        $budget->update(['status' => 'pending_approval']);
        $this->audit($budget, 'submitted_for_approval', $user, $before, $budget->fresh()->toArray(), null, $reason);

        return $budget;
    }

    public function approve(Project $project, User $user, string $decision, ?string $reason): ProjectBudget
    {
        return DB::transaction(function () use ($project, $user, $decision, $reason) {
            $budget = ProjectBudget::withoutGlobalScopes()->where('project_id', $project->id)->where('company_id', $project->company_id)->lockForUpdate()->firstOrFail();
            if ($budget->status !== 'pending_approval') {
                throw ValidationException::withMessages(['status' => 'RAB tidak sedang menunggu approval.']);
            }
            $before = $budget->toArray();
            if ($decision === 'reject') {
                $budget->update(['status' => 'rejected']);
                $this->audit($budget, 'rejected', $user, $before, $budget->fresh()->toArray(), null, $reason);

                return $budget;
            }
            $items = ProjectBudgetItem::withoutGlobalScopes()->where('project_budget_id', $budget->id)->orderBy('sort_order')->get();
            $summary = $this->summary($items, (float) $budget->tax_percent, (float) $budget->contingency_percent);
            $version = $budget->current_version + 1;
            ProjectBudgetVersion::withoutGlobalScopes()->create([
                'company_id' => $project->company_id, 'project_budget_id' => $budget->id, 'version' => $version,
                'subtotal' => $summary['subtotal'], 'grand_total' => $summary['grand_total'],
                'snapshot' => ['settings' => $budget->only(['currency', 'tax_percent', 'contingency_percent', 'notes']), 'items' => $items->toArray(), 'curve' => $this->curve($items)],
                'change_reason' => $reason, 'approved_by' => $user->id, 'approved_at' => now(),
            ]);
            $budget->update(['status' => 'approved', 'current_version' => $version, 'approved_by' => $user->id, 'approved_at' => now()]);
            $this->audit($budget, 'baseline_approved', $user, $before, $budget->fresh()->toArray(), null, $reason);

            return $budget;
        });
    }

    private function assertItem(Project $project, ProjectBudgetItem $item): void
    {
        $valid = ProjectBudget::withoutGlobalScopes()->whereKey($item->project_budget_id)
            ->where('project_id', $project->id)->where('company_id', $project->company_id)->exists();
        abort_unless($valid && (int) $item->company_id === (int) $project->company_id, 404);
    }

    private function ensureEditable(ProjectBudget $budget): void
    {
        if (! in_array($budget->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => 'Rincian hanya dapat disunting saat RAB berstatus draft atau rejected. Buat revisi draft terlebih dahulu.']);
        }
    }

    private function summary(Collection $items, float $taxPercent, float $contingencyPercent): array
    {
        $subtotal = $items->sum(fn ($item) => (float) $item->amount);
        $contingency = $subtotal * $contingencyPercent / 100;
        $tax = ($subtotal + $contingency) * $taxPercent / 100;
        $latest = $items->map(fn ($item) => $item->progressEntries?->sortByDesc('entry_date')->first())->filter();
        $actualCost = $latest->sum(fn ($entry) => (float) $entry->actual_cost);
        $progress = $subtotal > 0 ? $items->sum(function ($item) use ($subtotal) {
            $entry = $item->progressEntries?->sortByDesc('entry_date')->first();

            return ((float) $item->amount / $subtotal) * (float) ($entry?->actual_percent ?? 0);
        }) : 0;

        return compact('subtotal', 'contingency', 'tax', 'actualCost', 'progress') + [
            'grand_total' => $subtotal + $contingency + $tax,
            'variance' => ($subtotal + $contingency + $tax) - $actualCost,
        ];
    }

    private function curve(Collection $items): array
    {
        $subtotal = $items->sum(fn ($item) => (float) $item->amount);
        if ($subtotal <= 0) {
            return [];
        }
        $entryDates = $items->flatMap(fn ($item) => $item->progressEntries?->pluck('entry_date') ?? collect())->map(fn ($date) => $date->toDateString());
        $start = $items->pluck('planned_start')->filter()->min();
        $end = $items->pluck('planned_end')->filter()->max();
        $plannedDates = collect();
        if ($start && $end) {
            $cursor = $start->copy()->startOfDay();
            while ($cursor->lte($end)) {
                $plannedDates->push($cursor->toDateString());
                $cursor->addWeek();
            }
            $plannedDates->push($end->toDateString());
        }
        $dates = $entryDates->merge($plannedDates)->unique()->sort()->values();

        return $dates->map(function (string $date) use ($items, $subtotal) {
            $plannedProgress = $actualProgress = $actualCost = 0.0;
            foreach ($items as $item) {
                $entry = $item->progressEntries->filter(fn ($row) => $row->entry_date->toDateString() <= $date)->sortByDesc('entry_date')->first();
                $weight = (float) $item->amount / $subtotal;
                $planned = $entry ? (float) $entry->planned_percent : $this->linearPlannedPercent($item, $date);
                $plannedProgress += $weight * $planned;
                $actualProgress += $weight * (float) ($entry?->actual_percent ?? 0);
                $actualCost += (float) ($entry?->actual_cost ?? 0);
            }

            return [
                'date' => $date,
                'planned_progress' => round($plannedProgress, 2),
                'actual_progress' => round($actualProgress, 2),
                'planned_cost' => round($subtotal * $plannedProgress / 100, 2),
                'actual_cost' => round($actualCost, 2),
            ];
        })->all();
    }

    private function linearPlannedPercent(ProjectBudgetItem $item, string $date): float
    {
        if (! $item->planned_start || ! $item->planned_end) {
            return 0;
        }
        $at = Carbon::parse($date);
        if ($at->lt($item->planned_start)) {
            return 0;
        }
        if ($at->gte($item->planned_end)) {
            return 100;
        }
        $days = max(1, $item->planned_start->diffInDays($item->planned_end));

        return min(100, max(0, $item->planned_start->diffInDays($at) / $days * 100));
    }

    private function audit(ProjectBudget $budget, string $action, User $user, ?array $before, ?array $after, mixed $subject = null, ?string $reason = null): void
    {
        ProjectBudgetAudit::withoutGlobalScopes()->create([
            'company_id' => $budget->company_id, 'project_budget_id' => $budget->id, 'action' => $action,
            'subject_type' => $subject ? $subject::class : null, 'subject_id' => $subject?->id,
            'before' => $before, 'after' => $after, 'reason' => $reason, 'actor_id' => $user->id,
        ]);
    }
}
