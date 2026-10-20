<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Production Calendar - aggregate Work Order + Maintenance Schedule
 * + Shipment events into a unified timeline for visual planning.
 *
 * Range query: ?from=YYYY-MM-DD&to=YYYY-MM-DD (default: current month)
 */
class ProductionCalendarController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $companyId = (int) $request->user()->company_id;

        $from = $request->string('from')->toString() ?: now()->startOfMonth()->toDateString();
        $to   = $request->string('to')->toString()   ?: now()->endOfMonth()->toDateString();

        // Work Orders - planned_start_date / planned_complete_date as span
        $workOrders = DB::table('work_orders')
            ->leftJoin('products', 'products.id', '=', 'work_orders.product_id')
            ->where('work_orders.company_id', $companyId)
            ->whereNotNull('work_orders.planned_start_date')
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('work_orders.planned_start_date', [$from, $to])
                  ->orWhereBetween('work_orders.planned_complete_date', [$from, $to])
                  ->orWhere(function ($q2) use ($from, $to) {
                      $q2->where('work_orders.planned_start_date', '<=', $from)
                         ->where('work_orders.planned_complete_date', '>=', $to);
                  });
            })
            ->select(
                'work_orders.id',
                'work_orders.wo_number',
                'work_orders.qty_planned',
                'work_orders.qty_produced',
                'work_orders.status',
                'work_orders.planned_start_date',
                'work_orders.planned_complete_date',
                'products.code as product_code',
                'products.name as product_name',
            )
            ->get();

        // Maintenance Schedules - next_due_date in range
        $maintenance = DB::table('maintenance_schedules')
            ->leftJoin('fixed_assets', 'fixed_assets.id', '=', 'maintenance_schedules.fixed_asset_id')
            ->where('maintenance_schedules.company_id', $companyId)
            ->where('maintenance_schedules.status', 'active')
            ->whereBetween('maintenance_schedules.next_due_date', [$from, $to])
            ->select(
                'maintenance_schedules.id',
                'maintenance_schedules.task_code',
                'maintenance_schedules.title',
                'maintenance_schedules.next_due_date',
                'maintenance_schedules.assigned_dept',
                'maintenance_schedules.estimated_cost',
                'fixed_assets.asset_tag',
                'fixed_assets.name as asset_name',
            )
            ->get();

        // Shipments - ship_date or expected_delivery_date in range
        $shipments = DB::table('shipments')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('ship_date', [$from, $to])
                  ->orWhereBetween('expected_delivery_date', [$from, $to]);
            })
            ->select('id', 'do_number', 'customer_name', 'ship_date', 'expected_delivery_date', 'actual_delivery_date', 'status', 'carrier')
            ->get();

        // PO expected_delivery_date in range
        $purchaseOrders = DB::table('purchase_orders')
            ->leftJoin('fin_suppliers', 'fin_suppliers.id', '=', 'purchase_orders.supplier_id')
            ->where('purchase_orders.company_id', $companyId)
            ->whereNull('purchase_orders.deleted_at')
            ->whereBetween('purchase_orders.expected_delivery_date', [$from, $to])
            ->whereNotIn('purchase_orders.status', ['received', 'cancelled', 'closed'])
            ->select(
                'purchase_orders.id',
                'purchase_orders.po_number',
                'purchase_orders.expected_delivery_date',
                'purchase_orders.total',
                'purchase_orders.status',
                'fin_suppliers.name as supplier_name',
            )
            ->get();

        // Build event list - single unified array
        $events = [];

        foreach ($workOrders as $wo) {
            $events[] = [
                'id'         => "wo_{$wo->id}",
                'type'       => 'work_order',
                'title'      => "🏭 {$wo->wo_number}: {$wo->product_name}",
                'subtitle'   => "Qty {$wo->qty_produced}/{$wo->qty_planned} · " . ucfirst($wo->status),
                'start_date' => $wo->planned_start_date,
                'end_date'   => $wo->planned_complete_date ?? $wo->planned_start_date,
                'status'     => $wo->status,
                'color'      => match ($wo->status) {
                    'planned'     => '#94a3b8',
                    'released'    => '#3b82f6',
                    'in_progress' => '#f59e0b',
                    'completed'   => '#16a34a',
                    'closed'      => '#0f766e',
                    default       => '#64748b',
                },
                'ref_id'     => $wo->id,
                'ref_table'  => 'work_orders',
            ];
        }

        foreach ($maintenance as $m) {
            $events[] = [
                'id'         => "mt_{$m->id}",
                'type'       => 'maintenance',
                'title'      => "🔧 {$m->task_code}: {$m->title}",
                'subtitle'   => "Asset: {$m->asset_tag} {$m->asset_name}" . ($m->assigned_dept ? " · {$m->assigned_dept}" : ''),
                'start_date' => $m->next_due_date,
                'end_date'   => $m->next_due_date,
                'status'     => 'maintenance',
                'color'      => '#a855f7',
                'ref_id'     => $m->id,
                'ref_table'  => 'maintenance_schedules',
            ];
        }

        foreach ($shipments as $s) {
            $events[] = [
                'id'         => "sh_{$s->id}",
                'type'       => 'shipment',
                'title'      => "🚚 {$s->do_number}: {$s->customer_name}",
                'subtitle'   => ($s->carrier ?? '-') . ' · ' . ucfirst(str_replace('_', ' ', $s->status)),
                'start_date' => $s->ship_date,
                'end_date'   => $s->actual_delivery_date ?? $s->expected_delivery_date ?? $s->ship_date,
                'status'     => $s->status,
                'color'      => match ($s->status) {
                    'draft'       => '#94a3b8',
                    'packed'      => '#f59e0b',
                    'in_transit'  => '#3b82f6',
                    'delivered'   => '#16a34a',
                    'cancelled'   => '#ef4444',
                    default       => '#64748b',
                },
                'ref_id'     => $s->id,
                'ref_table'  => 'shipments',
            ];
        }

        foreach ($purchaseOrders as $po) {
            $events[] = [
                'id'         => "po_{$po->id}",
                'type'       => 'purchase_order',
                'title'      => "📦 {$po->po_number}: {$po->supplier_name}",
                'subtitle'   => 'Expected delivery · ' . ucfirst(str_replace('_', ' ', $po->status)),
                'start_date' => $po->expected_delivery_date,
                'end_date'   => $po->expected_delivery_date,
                'status'     => $po->status,
                'color'      => '#06b6d4',
                'ref_id'     => $po->id,
                'ref_table'  => 'purchase_orders',
            ];
        }

        // Sort by start_date
        usort($events, fn ($a, $b) => strcmp($a['start_date'], $b['start_date']));

        $byType = [
            'work_order'     => count($workOrders),
            'maintenance'    => count($maintenance),
            'shipment'       => count($shipments),
            'purchase_order' => count($purchaseOrders),
        ];

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'events' => $events,
            'count_by_type' => $byType,
            'total' => count($events),
        ]);
    }
}
