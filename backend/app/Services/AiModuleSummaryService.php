<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiModuleSummaryService
{
    public function summary(int $companyId, string $module): array
    {
        $metrics = match ($module) {
            'people' => $this->people($companyId),
            'marketing' => $this->marketing($companyId),
            'sales' => $this->sales($companyId),
            'finance', 'treasury' => $this->finance($companyId),
            'production' => $this->production($companyId),
            'distribution' => $this->distribution($companyId),
            'warehouse' => $this->warehouse($companyId),
            'procurement' => $this->procurement($companyId),
            default => [],
        };

        return [
            'module' => $module,
            'company_id' => $companyId,
            'generated_at' => now()->toIso8601String(),
            'metrics' => $metrics,
            'alerts' => $this->alerts($module, $metrics),
        ];
    }

    private function people(int $companyId): array
    {
        return [
            'employees_active' => $this->count('users', $companyId, fn ($q) => $q->whereNotIn('employment_status', ['resigned', 'terminated'])->whereNull('resigned_at')),
            'employees_inactive' => $this->count('users', $companyId, fn ($q) => $q->whereIn('employment_status', ['resigned', 'terminated'])),
            'leave_pending' => $this->statusCount('leaves', $companyId, ['pending']),
            'overtime_pending' => $this->statusCount('overtimes', $companyId, ['pending']),
            'salary_advance_pending' => $this->statusCount('salary_advances', $companyId, ['pending']),
        ];
    }

    private function marketing(int $companyId): array
    {
        return [
            'leads' => $this->count('crm_leads', $companyId),
            'pipelines' => $this->count('crm_pipelines', $companyId),
            'deals_open' => $this->statusCount('crm_deals', $companyId, ['open', 'qualified', 'proposal', 'negotiation']),
            'customers' => $this->count('fin_customers', $companyId),
            'quotations_open' => $this->statusCount('quotations', $companyId, ['draft', 'sent', 'viewed']),
        ];
    }

    private function finance(int $companyId): array
    {
        return [
            'invoices_outstanding' => $this->sumRemaining('fin_invoices', $companyId, ['sent', 'partial', 'overdue']),
            'bills_outstanding' => $this->sumRemaining('fin_bills', $companyId, ['received', 'partial', 'overdue']),
            'invoices_overdue' => $this->statusCount('fin_invoices', $companyId, ['overdue']),
            'bills_overdue' => $this->statusCount('fin_bills', $companyId, ['overdue']),
            'journals_posted' => $this->statusCount('fin_journals', $companyId, ['posted']),
            'bank_accounts_active' => $this->count('company_banks', $companyId, fn ($q) => $q->where('is_active', true)),
        ];
    }

    private function sales(int $companyId): array
    {
        return [
            'sales_orders_open' => $this->statusCount('sales_orders', $companyId, ['draft', 'confirmed']),
            'sales_orders_fulfilled' => $this->statusCount('sales_orders', $companyId, ['fulfilled']),
            'sales_returns_pending' => $this->statusCount('sales_returns', $companyId, ['draft', 'submitted']),
            'quotations_open' => $this->statusCount('quotations', $companyId, ['draft', 'sent', 'viewed']),
        ];
    }

    private function procurement(int $companyId): array
    {
        return [
            'purchase_requests_pending' => $this->statusCount('purchase_requests', $companyId, ['draft', 'submitted']),
            'rfqs_open' => $this->statusCount('procurement_rfqs', $companyId, ['draft', 'published', 'awarded']),
            'purchase_orders_open' => $this->statusCount('purchase_orders', $companyId, ['draft', 'approved', 'sent', 'partially_received']),
            'supplier_bills_outstanding' => $this->sumRemaining('fin_bills', $companyId, ['received', 'partial', 'overdue']),
        ];
    }

    private function production(int $companyId): array
    {
        return [
            'work_orders' => $this->count('work_orders', $companyId),
            'work_orders_in_progress' => $this->statusCount('work_orders', $companyId, ['in_progress']),
            'vendor_orders_active' => $this->statusCount('vendor_work_orders', $companyId, ['sent', 'in_progress', 'review']),
            'vendor_orders_review' => $this->statusCount('vendor_work_orders', $companyId, ['review']),
            'quality_inspections' => $this->count('quality_inspections', $companyId),
        ];
    }

    private function distribution(int $companyId): array
    {
        return [
            'shipments' => $this->count('shipments', $companyId),
            'shipments_active' => $this->statusCount('shipments', $companyId, ['draft', 'ready', 'shipped', 'in_transit']),
            'shipments_delivered' => $this->statusCount('shipments', $companyId, ['delivered']),
        ];
    }

    private function warehouse(int $companyId): array
    {
        return [
            'warehouses_active' => $this->count('inventory_warehouses', $companyId, fn ($q) => $q->where('is_active', true)),
            'stock_lines' => $this->count('inventory_stock', $companyId),
            'movements' => $this->count('inventory_movements', $companyId),
            'stock_counts_open' => $this->statusCount('stock_counts', $companyId, ['draft', 'counting']),
        ];
    }

    private function count(string $table, int $companyId, ?callable $scope = null): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
            return 0;
        }
        $query = DB::table($table)->where('company_id', $companyId);
        if ($scope) {
            $scope($query);
        }

        return $query->count();
    }

    private function statusCount(string $table, int $companyId, array $statuses): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status')) {
            return 0;
        }

        return $this->count($table, $companyId, fn ($q) => $q->whereIn('status', $statuses));
    }

    private function sumRemaining(string $table, int $companyId, array $statuses): float
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'total') || ! Schema::hasColumn($table, 'paid_amount')) {
            return 0.0;
        }

        return (float) DB::table($table)->where('company_id', $companyId)
            ->whereIn('status', $statuses)->selectRaw('COALESCE(SUM(total - paid_amount), 0) AS amount')->value('amount');
    }

    private function alerts(string $module, array $metrics): array
    {
        $alerts = [];
        foreach ($metrics as $key => $value) {
            if ((str_contains($key, 'pending') || str_contains($key, 'overdue') || str_contains($key, 'review')) && $value > 0) {
                $alerts[] = ['key' => $key, 'count' => $value, 'severity' => str_contains($key, 'overdue') ? 'high' : 'medium'];
            }
        }

        return $alerts;
    }
}
