<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Records audit-trail entries when a task is created, updated, commented on,
 * or labelled. Compares old/new attribute snapshots and persists one row per
 * changed field - so the frontend can show "Aji changed status from Todo →
 * In Progress" style timelines.
 */
class TaskActivityLogger
{
    /** Fields we track for `updated` activity entries. */
    private const TRACKED_FIELDS = [
        'title',
        'description',
        'status',
        'priority',
        'type',
        'story_points',
        'time_estimate',
        'time_spent',
        'due_date',
        'assigned_to',
        'sprint_id',
        'parent_task_id',
    ];

    public function created(Task $task, ?User $user): void
    {
        $this->record($task, $user, 'created');
    }

    public function deleted(Task $task, ?User $user): void
    {
        $this->record($task, $user, 'deleted');
    }

    public function commented(Task $task, ?User $user, string $body): void
    {
        $this->record(
            $task,
            $user,
            'commented',
            null,
            null,
            mb_strlen($body) > 120 ? mb_substr($body, 0, 117) . '…' : $body,
        );
    }

    public function labelChanged(Task $task, ?User $user, string $oldNames, string $newNames): void
    {
        if ($oldNames === $newNames) return;
        $this->record($task, $user, 'labels', 'labels', $oldNames ?: null, $newNames ?: null);
    }

    /**
     * Compare $old (attribute array before update) with the fresh model and
     * log one activity row per changed tracked field.
     */
    public function updated(Task $task, ?User $user, array $old): void
    {
        $fresh = $task->fresh();
        if (! $fresh) return;

        foreach (self::TRACKED_FIELDS as $field) {
            $before = $old[$field] ?? null;
            $after  = $fresh->getAttribute($field);

            // Normalise dates to YYYY-MM-DD for stable comparison
            if ($before instanceof Carbon) $before = $before->toDateString();
            if ($after instanceof Carbon)  $after  = $after->toDateString();

            if ($this->valuesEqual($before, $after)) continue;

            $this->record(
                $task,
                $user,
                $field === 'status' ? 'status_changed'
                    : ($field === 'assigned_to' ? 'assigned'
                    : ($field === 'sprint_id' ? 'sprint_moved'
                    : 'updated')),
                $field,
                $before === null ? null : (string) $before,
                $after === null ? null : (string) $after,
            );
        }
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === null && $b === null) return true;
        if ($a === null || $b === null) return false;
        return (string) $a === (string) $b;
    }

    private function record(
        Task $task,
        ?User $user,
        string $action,
        ?string $field = null,
        ?string $from = null,
        ?string $to = null,
    ): void {
        TaskActivity::create([
            'task_id'    => $task->id,
            'user_id'    => $user?->id,
            'action'     => $action,
            'field'      => $field,
            'from_value' => $from,
            'to_value'   => $to,
            'created_at' => now(),
        ]);
    }
}
