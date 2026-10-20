<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Notification service - create + query in-app notifications.
 *
 * Notify methods:
 *   notifyUser(userId, ...)          - single user
 *   notifyRole(role, ...)            - broadcast to all users with role
 *   generateSystemAlerts()           - scan modules + emit alerts (run via cron)
 */
class NotificationService
{
    public function notifyUser(string $userId, int $companyId, array $data): AppNotification
    {
        return AppNotification::create(array_merge($data, [
            'company_id'  => $companyId,
            'user_id'     => $userId,
            'created_at'  => now(),
        ]));
    }

    public function notifyRole(string $role, int $companyId, array $data): AppNotification
    {
        return AppNotification::create(array_merge($data, [
            'company_id'    => $companyId,
            'audience_role' => $role,
            'created_at'    => now(),
        ]));
    }

    /**
     * Get unread + recent notifications for a user (also includes role-broadcasts).
     */
    public function forUser(User $user, int $limit = 50): array
    {
        $rows = AppNotification::query()
            ->where('company_id', $user->company_id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere(function ($q2) use ($user) {
                      $q2->whereNull('user_id')
                         ->whereIn('audience_role', [$user->group, 'all']);
                  });
            })
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $rows->map(fn (AppNotification $n) => [
            'id'           => $n->id,
            'type'         => $n->type,
            'severity'     => $n->severity,
            'title'        => $n->title,
            'body'         => $n->body,
            'link_url'     => $n->link_url,
            'ref_table'    => $n->ref_table,
            'ref_id'       => $n->ref_id,
            'is_read'      => $n->read_at !== null,
            'created_at'   => $n->created_at?->toIso8601String(),
            'time_ago'     => $n->created_at?->diffForHumans(),
        ])->all();
    }

    public function unreadCount(User $user): int
    {
        return AppNotification::query()
            ->where('company_id', $user->company_id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere(function ($q2) use ($user) {
                      $q2->whereNull('user_id')
                         ->whereIn('audience_role', [$user->group, 'all']);
                  });
            })
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->count();
    }

    public function markRead(User $user, ?int $id = null): int
    {
        $q = AppNotification::query()
            ->where('company_id', $user->company_id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere(function ($q2) use ($user) {
                      $q2->whereNull('user_id')
                         ->whereIn('audience_role', [$user->group, 'all']);
                  });
            })
            ->whereNull('read_at');
        if ($id) $q->where('id', $id);
        return $q->update(['read_at' => now()]);
    }

    /**
     * Scan all modules + create alerts. Idempotent: doesn't re-create if same alert
     * for same ref_id within 24h.
     */
    public function generateSystemAlerts(int $companyId): array
    {
        $created = ['quote_expiring' => 0, 'po_overdue' => 0, 'maintenance_due' => 0, 'doc_expiring' => 0, 'sla_breach' => 0];
        $now = now();

        // 1. Quotes expiring within 7 days
        $quotes = DB::table('quotations')
            ->where('company_id', $companyId)
            ->whereIn('status', ['draft', 'sent'])
            ->whereNotNull('valid_until')
            ->whereBetween('valid_until', [$now->toDateString(), $now->copy()->addDays(7)->toDateString()])
            ->whereNull('deleted_at')
            ->select('id', 'quote_number', 'title', 'valid_until', 'customer_name')
            ->get();
        foreach ($quotes as $q) {
            if ($this->existsRecent($companyId, 'quote_expiring', 'quotations', $q->id)) continue;
            $this->notifyRole('admin', $companyId, [
                'type'      => 'quote_expiring',
                'severity'  => 'warning',
                'title'     => "RAB {$q->quote_number} segera kedaluwarsa",
                'body'      => "Penawaran ke {$q->customer_name} berlaku sampai " . date('d M Y', strtotime($q->valid_until)),
                'link_url'  => '/quotations',
                'ref_table' => 'quotations',
                'ref_id'    => $q->id,
            ]);
            $created['quote_expiring']++;
        }

        // 2. PO overdue (expected_delivery < today, not received/cancelled)
        $pos = DB::table('purchase_orders')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['received', 'cancelled', 'closed'])
            ->where('expected_delivery_date', '<', $now->toDateString())
            ->select('id', 'po_number', 'supplier_id', 'expected_delivery_date')
            ->get();
        foreach ($pos as $po) {
            if ($this->existsRecent($companyId, 'po_overdue', 'purchase_orders', $po->id)) continue;
            $this->notifyRole('admin', $companyId, [
                'type'      => 'po_overdue',
                'severity'  => 'error',
                'title'     => "PO {$po->po_number} overdue",
                'body'      => "Expected delivery " . date('d M Y', strtotime($po->expected_delivery_date)) . ' - belum diterima.',
                'link_url'  => '/purchase-orders',
                'ref_table' => 'purchase_orders',
                'ref_id'    => $po->id,
            ]);
            $created['po_overdue']++;
        }

        // 3. Maintenance due within 7 days or overdue
        $maintenance = DB::table('maintenance_schedules')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('next_due_date', '<=', $now->copy()->addDays(7)->toDateString())
            ->select('id', 'task_code', 'title', 'next_due_date')
            ->get();
        foreach ($maintenance as $m) {
            if ($this->existsRecent($companyId, 'maintenance_due', 'maintenance_schedules', $m->id)) continue;
            $isOverdue = $m->next_due_date < $now->toDateString();
            $this->notifyRole('admin', $companyId, [
                'type'      => 'maintenance_due',
                'severity'  => $isOverdue ? 'error' : 'warning',
                'title'     => $isOverdue ? "Maintenance {$m->task_code} OVERDUE" : "Maintenance {$m->task_code} jatuh tempo",
                'body'      => "{$m->title} - due " . date('d M Y', strtotime($m->next_due_date)),
                'link_url'  => '/maintenance',
                'ref_table' => 'maintenance_schedules',
                'ref_id'    => $m->id,
            ]);
            $created['maintenance_due']++;
        }

        // 4. Document expiring within 30 days
        $docs = DB::table('company_documents')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$now->toDateString(), $now->copy()->addDays(30)->toDateString()])
            ->whereNull('deleted_at')
            ->select('id', 'doc_code', 'title', 'expiry_date')
            ->get();
        foreach ($docs as $d) {
            if ($this->existsRecent($companyId, 'doc_expiring', 'company_documents', $d->id)) continue;
            $this->notifyRole('admin', $companyId, [
                'type'      => 'doc_expiring',
                'severity'  => 'warning',
                'title'     => "Dokumen {$d->doc_code} mendekati expiry",
                'body'      => "{$d->title} - expires " . date('d M Y', strtotime($d->expiry_date)),
                'link_url'  => '/documents',
                'ref_table' => 'company_documents',
                'ref_id'    => $d->id,
            ]);
            $created['doc_expiring']++;
        }

        // 5. Service Ticket SLA breached
        if (\Schema::hasColumn('complaints', 'is_response_breached')) {
            $tickets = DB::table('complaints')
                ->where('company_id', $companyId)
                ->whereNotIn('status', ['resolved', 'closed'])
                ->where(function ($q) {
                    $q->where('is_response_breached', true)
                      ->orWhere('is_resolution_breached', true);
                })
                ->select('id', 'ticket_number', 'subject', 'is_response_breached', 'is_resolution_breached')
                ->get();
            foreach ($tickets as $t) {
                if ($this->existsRecent($companyId, 'sla_breach', 'complaints', $t->id)) continue;
                $type = $t->is_resolution_breached ? 'Resolution' : 'Response';
                $this->notifyRole('admin', $companyId, [
                    'type'      => 'sla_breach',
                    'severity'  => 'error',
                    'title'     => "Ticket {$t->ticket_number} - {$type} SLA breached",
                    'body'      => $t->subject,
                    'link_url'  => '/complaints',
                    'ref_table' => 'complaints',
                    'ref_id'    => $t->id,
                ]);
                $created['sla_breach']++;
            }
        }

        return $created;
    }

    protected function existsRecent(int $companyId, string $type, string $refTable, $refId): bool
    {
        return AppNotification::query()
            ->where('company_id', $companyId)
            ->where('type', $type)
            ->where('ref_table', $refTable)
            ->where('ref_id', $refId)
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }
}
