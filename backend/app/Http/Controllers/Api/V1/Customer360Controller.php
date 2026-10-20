<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer 360° - aggregate view per customer across modules:
 *   - Customer profile (from fin_customers)
 *   - Invoices + outstanding receivable
 *   - Shipments (delivery history)
 *   - Service Tickets (complaints)
 *   - Surveys + NPS
 *   - CRM Deals (matched by company name)
 */
class Customer360Controller extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $companyId = (int) $request->user()->company_id;

        // Aggregated KPI per customer
        $customers = DB::table('fin_customers')
            ->where('company_id', $companyId)
            // Kolomnya bernama `npwp` (lihat migrasi create_fin_customers_table).
            // Dulu tertulis `tax_id` -> SQL error 1054 -> halaman Customer 360°
            // selalu 500 untuk semua pengguna. Kunci respons tetap `tax_id`
            // supaya frontend tidak perlu berubah.
            ->select('id', 'name', 'email', 'phone', 'npwp', 'address')
            ->orderBy('name')
            ->get();

        $rows = $customers->map(function ($c) use ($companyId) {
            // Invoice stats
            $invStats = DB::table('fin_invoices')
                ->where('company_id', $companyId)
                ->where('customer_id', $c->id)
                ->selectRaw('COUNT(*) as inv_count, SUM(total) as inv_total, SUM(total - paid_amount) as outstanding, MAX(date) as last_inv_date')
                ->first();

            // Shipment stats
            $shipCount = DB::table('shipments')
                ->where('company_id', $companyId)
                ->where('customer_id', $c->id)
                ->whereNull('deleted_at')
                ->count();

            // Service tickets - link via customer_email (since complaint table doesn't have customer_id FK)
            $ticketCount = $c->email ? DB::table('complaints')
                ->where('company_id', $companyId)
                ->where('customer_email', $c->email)
                ->count() : 0;

            $openTickets = $c->email ? DB::table('complaints')
                ->where('company_id', $companyId)
                ->where('customer_email', $c->email)
                ->whereNotIn('status', ['resolved', 'closed'])
                ->count() : 0;

            // Survey NPS (latest)
            $latestSurvey = DB::table('customer_surveys')
                ->where('company_id', $companyId)
                ->where('customer_id', $c->id)
                ->whereNotNull('nps_score')
                ->orderByDesc('responded_at')
                ->first();

            return [
                'id'             => $c->id,
                'name'           => $c->name,
                'email'          => $c->email,
                'phone'          => $c->phone,
                'tax_id'         => $c->npwp,
                'invoice_count'  => (int) ($invStats->inv_count ?? 0),
                'total_revenue'  => round((float) ($invStats->inv_total ?? 0), 2),
                'outstanding'    => round((float) ($invStats->outstanding ?? 0), 2),
                'last_invoice'   => $invStats->last_inv_date ?? null,
                'shipment_count' => (int) $shipCount,
                'ticket_count'   => (int) $ticketCount,
                'open_tickets'   => (int) $openTickets,
                'last_nps_score' => $latestSurvey?->nps_score,
                'last_nps_category' => $latestSurvey?->nps_category,
            ];
        })->all();

        // Sort by total_revenue desc
        usort($rows, fn ($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

        return $this->success([
            'data'  => $rows,
            'total_customers' => count($rows),
            'total_revenue'   => round(array_sum(array_column($rows, 'total_revenue')), 2),
            'total_outstanding' => round(array_sum(array_column($rows, 'outstanding')), 2),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $companyId = (int) $request->user()->company_id;

        $customer = DB::table('fin_customers')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();
        if (!$customer) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        // Invoices
        $invoices = DB::table('fin_invoices')
            ->where('company_id', $companyId)
            ->where('customer_id', $id)
            ->orderByDesc('date')
            ->limit(50)
            ->get(['id', 'number', 'date', 'due_date', 'total', 'paid_amount', 'status']);

        // Shipments
        $shipments = DB::table('shipments')
            ->where('company_id', $companyId)
            ->where('customer_id', $id)
            ->whereNull('deleted_at')
            ->orderByDesc('ship_date')
            ->limit(30)
            ->get(['id', 'do_number', 'ship_date', 'expected_delivery_date', 'actual_delivery_date', 'status', 'shipping_cost']);

        // Tickets - link via email
        $tickets = $customer->email ? DB::table('complaints')
            ->where('company_id', $companyId)
            ->where('customer_email', $customer->email)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'ticket_number', 'subject', 'category', 'priority', 'status', 'created_at', 'resolved_at']) : collect();

        // Surveys
        $surveys = DB::table('customer_surveys')
            ->where('company_id', $companyId)
            ->where('customer_id', $id)
            ->orderByDesc('sent_date')
            ->limit(30)
            ->get(['id', 'survey_token', 'trigger_type', 'sent_date', 'responded_at', 'nps_score', 'nps_category', 'comment', 'status']);

        // CRM Deals - try match by company name
        $deals = DB::table('crm_deals as d')
            ->leftJoin('crm_companies as cc', 'cc.id', '=', 'd.crm_company_id')
            ->where('d.company_id', $companyId)
            ->where('cc.name', 'like', "%{$customer->name}%")
            ->orderByDesc('d.created_at')
            ->limit(30)
            ->select('d.id', 'd.code', 'd.title', 'd.amount', 'd.probability', 'd.status', 'd.expected_close_date', 'd.closed_at', 'cc.name as company_name')
            ->get();

        // Stats summary
        $totalRevenue = $invoices->sum('total');
        $totalPaid    = $invoices->sum('paid_amount');
        $outstanding  = $totalRevenue - $totalPaid;
        $avgNps = $surveys->whereNotNull('nps_score')->avg('nps_score');

        return $this->success([
            'customer' => [
                'id'      => $customer->id,
                'name'    => $customer->name,
                'email'   => $customer->email,
                'phone'   => $customer->phone,
                'tax_id'  => $customer->npwp,
                'address' => $customer->address,
            ],
            'summary' => [
                'total_revenue'    => round((float) $totalRevenue, 2),
                'total_paid'       => round((float) $totalPaid, 2),
                'outstanding'      => round((float) $outstanding, 2),
                'invoice_count'    => $invoices->count(),
                'shipment_count'   => $shipments->count(),
                'ticket_count'     => $tickets->count(),
                'open_tickets'     => $tickets->whereNotIn('status', ['resolved', 'closed'])->count(),
                'survey_count'     => $surveys->whereNotNull('responded_at')->count(),
                'avg_nps_score'    => $avgNps ? round((float) $avgNps, 1) : null,
                'crm_deal_count'   => $deals->count(),
                'crm_open_deals'   => $deals->where('status', 'open')->count(),
                'crm_won_amount'   => round((float) $deals->where('status', 'won')->sum('amount'), 2),
            ],
            'invoices'  => $invoices,
            'shipments' => $shipments,
            'tickets'   => $tickets,
            'surveys'   => $surveys,
            'deals'     => $deals,
        ]);
    }
}
