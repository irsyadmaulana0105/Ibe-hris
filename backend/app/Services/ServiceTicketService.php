<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\ServiceTicketActivity;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service Ticket workflow on top of existing complaints table.
 *
 * Status lifecycle:
 *   new → in_progress → resolved → closed
 *               ↓               ↑
 *           on_hold ─────────────
 *               ↑
 *          reopened (from resolved/closed)
 *
 * SLA: target_response_at and target_resolution_at computed at ticket create
 * from priority defaults. Breach check runs on read + on transition.
 *
 * Priority → default SLA minutes:
 *   critical → response 15m, resolution 240m (4h)
 *   high     → response 30m, resolution 480m (8h)
 *   normal   → response 60m, resolution 1440m (24h)
 *   low      → response 240m (4h), resolution 4320m (3 days)
 */
class ServiceTicketService
{
    public const SLA_DEFAULTS = [
        'critical' => ['response' => 15,  'resolution' => 240],
        'high'     => ['response' => 30,  'resolution' => 480],
        'normal'   => ['response' => 60,  'resolution' => 1440],
        'low'      => ['response' => 240, 'resolution' => 4320],
    ];

    public function applySlaDefaults(Complaint $ticket): void
    {
        $defaults = self::SLA_DEFAULTS[$ticket->priority] ?? self::SLA_DEFAULTS['normal'];
        $ticket->sla_response_minutes   = $ticket->sla_response_minutes   ?: $defaults['response'];
        $ticket->sla_resolution_minutes = $ticket->sla_resolution_minutes ?: $defaults['resolution'];
        $base = $ticket->created_at ?? Carbon::now();
        $ticket->target_response_at   = $base->copy()->addMinutes($ticket->sla_response_minutes);
        $ticket->target_resolution_at = $base->copy()->addMinutes($ticket->sla_resolution_minutes);
    }

    public function assign(User $actor, Complaint $ticket, ?string $assigneeId, ?string $note = null): Complaint
    {
        $from = $ticket->assigned_to_user_id;
        return DB::transaction(function () use ($actor, $ticket, $assigneeId, $note, $from) {
            $ticket->assigned_to_user_id = $assigneeId;
            $ticket->save();
            $this->logActivity($ticket, $actor, 'assignment', $note, [
                'from' => $from,
                'to'   => $assigneeId,
            ]);
            return $ticket;
        });
    }

    public function comment(User $actor, Complaint $ticket, string $body, bool $isInternal = true): ServiceTicketActivity
    {
        return DB::transaction(function () use ($actor, $ticket, $body, $isInternal) {
            // First reply by staff = mark first_response_at
            if (!$ticket->first_response_at && !$isInternal) {
                $ticket->first_response_at = now();
                $ticket->save();
            }
            return $this->logActivity($ticket, $actor, 'comment', $body, null, $isInternal);
        });
    }

    public function transition(User $actor, Complaint $ticket, string $to, ?string $note = null): Complaint
    {
        $allowed = [
            'new'         => ['in_progress', 'on_hold', 'closed'],
            'in_progress' => ['on_hold', 'resolved', 'closed'],
            'on_hold'     => ['in_progress', 'closed'],
            'resolved'    => ['closed', 'reopened'],
            'closed'      => ['reopened'],
            'reopened'    => ['in_progress', 'on_hold'],
        ];
        $from = $ticket->status;
        if (!in_array($to, $allowed[$from] ?? [], true)) {
            throw new \RuntimeException("Invalid transition: {$from} → {$to}");
        }
        return DB::transaction(function () use ($actor, $ticket, $from, $to, $note) {
            $ticket->status = $to;
            if ($to === 'resolved') {
                $ticket->resolved_at = now();
                $ticket->resolved_by_user_id = $actor->id;
                if ($note) $ticket->resolution_notes = $note;
            } elseif ($to === 'reopened') {
                $ticket->reopened_at = now();
                $ticket->reopen_count = ($ticket->reopen_count ?? 0) + 1;
                // Reset resolution target relative to reopen time
                $ticket->target_resolution_at = now()->addMinutes($ticket->sla_resolution_minutes);
            }
            $ticket->save();
            $this->logActivity($ticket, $actor, 'status_change', $note, [
                'from' => $from,
                'to'   => $to,
            ]);
            return $ticket;
        });
    }

    /**
     * Refresh SLA breach flags. Idempotent - call before serving ticket reads.
     */
    public function refreshSla(Complaint $ticket): Complaint
    {
        $changed = false;
        $now = Carbon::now();
        $responseDone = $ticket->first_response_at !== null || in_array($ticket->status, ['resolved', 'closed'], true);
        $resolutionDone = in_array($ticket->status, ['resolved', 'closed'], true);

        $responseBreach = !$responseDone && $ticket->target_response_at && $now->greaterThan($ticket->target_response_at);
        $resolutionBreach = !$resolutionDone && $ticket->target_resolution_at && $now->greaterThan($ticket->target_resolution_at);

        if ($responseBreach !== (bool) $ticket->is_response_breached) {
            $ticket->is_response_breached = $responseBreach;
            $changed = true;
            if ($responseBreach) {
                $this->logActivity($ticket, null, 'sla_breach', 'Response SLA breached', ['type' => 'response']);
            }
        }
        if ($resolutionBreach !== (bool) $ticket->is_resolution_breached) {
            $ticket->is_resolution_breached = $resolutionBreach;
            $changed = true;
            if ($resolutionBreach) {
                $this->logActivity($ticket, null, 'sla_breach', 'Resolution SLA breached', ['type' => 'resolution']);
            }
        }
        if ($changed) $ticket->saveQuietly();
        return $ticket;
    }

    protected function logActivity(
        Complaint $ticket,
        ?User $actor,
        string $type,
        ?string $body,
        ?array $meta = null,
        bool $isInternal = true,
    ): ServiceTicketActivity {
        return ServiceTicketActivity::create([
            'complaint_id'   => $ticket->id,
            'company_id'     => $ticket->company_id,
            'type'           => $type,
            'actor_user_id'  => $actor?->id,
            'actor_label'    => $actor ? $actor->name : 'System',
            'body'           => $body,
            'meta'           => $meta,
            'is_internal'    => $isInternal,
            'created_at'     => now(),
        ]);
    }
}
