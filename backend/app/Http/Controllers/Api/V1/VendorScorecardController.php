<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Vendor Performance Scorecard - aggregate rating per supplier dari:
 *   - PO delivery: on-time vs late (purchase_orders.received_at vs PO dates)
 *   - Spend volume (sum of paid bills)
 *   - Payment behavior: avg days to pay
 *   - Order frequency
 *
 * Score 0-100:
 *   - 40% on-time delivery rate
 *   - 30% spend reliability (consistent ordering)
 *   - 30% order count + recency
 */
class VendorScorecardController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $companyId = (int) $request->user()->company_id;

        $suppliers = DB::table('fin_suppliers')
            ->where('company_id', $companyId)
            ->select('id', 'name', 'email', 'phone', 'npwp as tax_id')
            ->orderBy('name')
            ->get();

        $rows = $suppliers->map(function ($s) use ($companyId) {
            // PO stats - purchase_orders supplier_id is INT FK to fin_suppliers
            $poStats = DB::table('purchase_orders')
                ->where('company_id', $companyId)
                ->where('supplier_id', $s->id)
                ->whereNull('deleted_at')
                ->selectRaw('COUNT(*) as po_count,
                             SUM(CASE WHEN status IN ("received", "closed") THEN 1 ELSE 0 END) as po_received,
                             SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as po_cancelled,
                             MAX(date) as last_date,
                             SUM(total) as total_value')
                ->first();

            // Bill stats - payment behavior
            $billStats = DB::table('fin_bills')
                ->where('company_id', $companyId)
                ->where('supplier_id', $s->id)
                ->selectRaw('COUNT(*) as bill_count,
                             SUM(total) as total_billed,
                             SUM(paid_amount) as total_paid,
                             SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) as paid_count,
                             SUM(CASE WHEN paid_at IS NOT NULL AND due_date IS NOT NULL
                                      THEN DATEDIFF(paid_at, due_date) ELSE NULL END) as total_lateness,
                             SUM(CASE WHEN paid_at IS NOT NULL AND due_date IS NOT NULL THEN 1 ELSE 0 END) as paid_with_due')
                ->first();

            $poCount = (int) ($poStats->po_count ?? 0);
            $poReceived = (int) ($poStats->po_received ?? 0);
            $deliveryRate = $poCount > 0 ? round(($poReceived / $poCount) * 100, 1) : 0;

            $billCount = (int) ($billStats->bill_count ?? 0);
            $totalBilled = (float) ($billStats->total_billed ?? 0);
            $totalPaid = (float) ($billStats->total_paid ?? 0);
            $outstanding = $totalBilled - $totalPaid;

            $avgLateness = $billStats->paid_with_due > 0
                ? round(((float) $billStats->total_lateness) / $billStats->paid_with_due, 1)
                : 0;

            // Order recency (days since last PO)
            $daysSinceLastPo = $poStats->last_date
                ? (int) round(now()->diffInDays(\Carbon\Carbon::parse($poStats->last_date), false) * -1)
                : null;

            // Score calculation
            $deliveryScore = $deliveryRate; // 0-100
            $recencyScore = $daysSinceLastPo === null ? 0
                : max(0, 100 - min(100, $daysSinceLastPo / 3)); // 0 days = 100, 300+ days = 0
            $volumeScore = $poCount > 0 ? min(100, $poCount * 10) : 0; // 10 POs = 100
            $score = round(($deliveryScore * 0.4) + ($recencyScore * 0.3) + ($volumeScore * 0.3), 1);

            $tier = $score >= 80 ? 'preferred' : ($score >= 60 ? 'approved' : ($score >= 40 ? 'conditional' : 'review'));

            return [
                'id' => $s->id,
                'name' => $s->name,
                'email' => $s->email,
                'phone' => $s->phone,
                'tax_id' => $s->tax_id,
                'po_count' => $poCount,
                'po_received' => $poReceived,
                'po_cancelled' => (int) ($poStats->po_cancelled ?? 0),
                'delivery_rate' => $deliveryRate,
                'last_date' => $poStats->last_date,
                'days_since_last_po' => $daysSinceLastPo,
                'total_po_value' => round((float) ($poStats->total_value ?? 0), 2),
                'bill_count' => $billCount,
                'total_billed' => round($totalBilled, 2),
                'total_paid' => round($totalPaid, 2),
                'outstanding' => round($outstanding, 2),
                'paid_count' => (int) ($billStats->paid_count ?? 0),
                'avg_payment_lateness_days' => $avgLateness,
                'score' => $score,
                'tier' => $tier,
                'delivery_score' => $deliveryScore,
                'recency_score' => round($recencyScore, 1),
                'volume_score' => $volumeScore,
            ];
        })->all();

        // Sort by score desc
        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score']);

        // Roll-up
        $totals = [
            'supplier_count' => count($rows),
            'preferred' => count(array_filter($rows, fn ($r) => $r['tier'] === 'preferred')),
            'approved' => count(array_filter($rows, fn ($r) => $r['tier'] === 'approved')),
            'conditional' => count(array_filter($rows, fn ($r) => $r['tier'] === 'conditional')),
            'review' => count(array_filter($rows, fn ($r) => $r['tier'] === 'review')),
            'total_po_value' => round(array_sum(array_column($rows, 'total_po_value')), 2),
            'total_outstanding' => round(array_sum(array_column($rows, 'outstanding')), 2),
            'avg_delivery_rate' => count($rows) > 0
                ? round(array_sum(array_column($rows, 'delivery_rate')) / count($rows), 1)
                : 0,
        ];

        return $this->success(['data' => $rows, 'totals' => $totals]);
    }
}
