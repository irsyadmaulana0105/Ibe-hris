<?php

namespace App\Services;

use App\Models\GovernanceRecord;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GovernanceService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function list(int $companyId, array $filters): LengthAwarePaginator
    {
        return GovernanceRecord::withoutGlobalScopes()
            ->with('owner:id,name,email')
            ->where('company_id', $companyId)
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(min((int) ($filters['per_page'] ?? 50), 100));
    }

    public function summary(int $companyId): array
    {
        $rows = GovernanceRecord::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->selectRaw('type, status, COUNT(*) total')
            ->groupBy('type', 'status')
            ->get();

        return [
            'total' => $rows->sum('total'),
            'open' => $rows->whereNotIn('status', ['closed', 'verified'])->sum('total'),
            'overdue' => GovernanceRecord::withoutGlobalScopes()->where('company_id', $companyId)
                ->whereNotIn('status', ['closed', 'verified'])->whereDate('due_date', '<', now())->count(),
            'by_type' => $rows->groupBy('type')->map(fn ($items) => [
                'total' => $items->sum('total'),
                'closed' => $items->whereIn('status', ['closed', 'verified'])->sum('total'),
            ])->all(),
        ];
    }

    public function create(User $actor, array $data, Request $request): GovernanceRecord
    {
        return DB::transaction(function () use ($actor, $data, $request) {
            $record = GovernanceRecord::withoutGlobalScopes()->create([
                ...$data,
                'company_id' => $actor->company_id,
                'reference' => $data['reference'] ?? $this->nextReference($actor->company_id, $data['type']),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->audit->log('governance.record.created', $actor, 'governance_record', $record->id, [
                'type' => $record->type, 'reference' => $record->reference,
            ], $request);

            return $record->load('owner:id,name,email');
        });
    }

    public function update(User $actor, GovernanceRecord $record, array $data, Request $request): GovernanceRecord
    {
        abort_unless($record->company_id === $actor->company_id, 404);
        $before = $record->only(array_keys($data));
        if (in_array($data['status'] ?? null, ['closed', 'verified'], true)) {
            $data['closed_at'] = now();
        } elseif (isset($data['status'])) {
            $data['closed_at'] = null;
        }
        $record->fill([...$data, 'updated_by' => $actor->id])->save();
        $this->audit->log('governance.record.updated', $actor, 'governance_record', $record->id, [
            'before' => $before, 'after' => $record->only(array_keys($data)),
        ], $request);

        return $record->fresh()->load('owner:id,name,email');
    }

    private function nextReference(int $companyId, string $type): string
    {
        $prefix = match ($type) {
            'risk' => 'RSK', 'capa' => 'CAPA', 'recovery_test' => 'DRT',
            'continuity_exercise' => 'BCX', 'accessibility_audit' => 'A11Y', default => 'GOV',
        };
        $next = GovernanceRecord::withoutGlobalScopes()->where('company_id', $companyId)->where('type', $type)->count() + 1;

        return sprintf('%s-%s-%04d', $prefix, now()->format('Y'), $next);
    }
}
