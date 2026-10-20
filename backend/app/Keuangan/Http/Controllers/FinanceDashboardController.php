<?php

namespace App\Keuangan\Http\Controllers;

use App\Keuangan\Models\Account;
use App\Keuangan\Models\Bill;
use App\Keuangan\Models\Invoice;
use App\Keuangan\Models\Journal;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Reimbursement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard widget data untuk Finance role.
 * Akumulasi: cash position, AR/AP outstanding, MTD revenue/expense, recent activity.
 */
class FinanceDashboardController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'finance.module.access');

        $companyId = (int) $admin->company_id;
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $today = Carbon::now()->toDateString();

        // 1. Cash position - saldo akhir di Kas (1110) + Bank (1120) dari general ledger posted
        $cashPosition = $this->accountBalance($companyId, ['1110', '1120']);

        // 2. AR (Piutang) - invoices sent/partial/overdue
        $arTotal = Invoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) AS outstanding')
            ->value('outstanding');

        // 3. AP (Hutang) - bills received/partial/overdue
        $apTotal = Bill::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['received', 'partial', 'overdue'])
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) AS outstanding')
            ->value('outstanding');

        // 4. Revenue MTD - sum of invoices sent this month
        $revenueMtd = Invoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['sent', 'partial', 'paid'])
            ->where('date', '>=', $monthStart)
            ->sum('total');

        // 5. Expense MTD - sum debit di akun beban (5xxx) di journal posted MTD
        $expenseMtd = DB::table('fin_journal_lines AS jl')
            ->join('fin_journals AS j', 'j.id', '=', 'jl.journal_id')
            ->join('fin_accounts AS a', 'a.id', '=', 'jl.account_id')
            ->where('j.company_id', $companyId)
            ->where('j.status', 'posted')
            ->where('j.date', '>=', $monthStart)
            ->where('a.type', 'expense')
            ->selectRaw('COALESCE(SUM(jl.debit), 0) AS total')
            ->value('total');

        // 6. Reimburse menunggu Finance settle
        $reimbursePending = Reimbursement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'hr_approved')
            ->count();

        // 7. Recent journals (5 last posted)
        $recentJournals = Journal::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'number', 'date', 'description', 'reference', 'total_debit']);

        // 8. AR Aging (outstanding invoices bucketed)
        $arAging = $this->agingBuckets(
            Invoice::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('status', ['sent', 'partial', 'overdue'])
                ->get(['date', 'total', 'paid_amount']),
            'date'
        );

        // 9. AP Aging
        $apAging = $this->agingBuckets(
            Bill::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('status', ['received', 'partial', 'overdue'])
                ->get(['date', 'total', 'paid_amount']),
            'date'
        );

        // 10. Cash Flow Trend (12 minggu terakhir)
        $cashflowWeekly = $this->cashflowWeekly($companyId);

        // 11. Top customers by outstanding AR
        $topCustomers = Invoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->selectRaw('customer_name, COUNT(*) AS invoice_count, COALESCE(SUM(total - paid_amount), 0) AS outstanding')
            ->groupBy('customer_name')
            ->orderByDesc('outstanding')
            ->limit(5)
            ->get();

        // 12. Top suppliers by outstanding AP
        $topSuppliers = Bill::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['received', 'partial', 'overdue'])
            ->selectRaw('supplier_name, COUNT(*) AS bill_count, COALESCE(SUM(total - paid_amount), 0) AS outstanding')
            ->groupBy('supplier_name')
            ->orderByDesc('outstanding')
            ->limit(5)
            ->get();

        // 13. Cash Conversion Cycle (CCC) - financial management KPI klasik.
        // CCC = DSO - DPO (kita skip DIO karena tidak ada inventory tracking yet)
        //   DSO = avg days from invoice.date → invoice.paid_at (paid invoices last 90d)
        //   DPO = avg days from bill.date → bill.paid_at (paid bills last 90d)
        // Interpretasi: makin kecil makin sehat (cash kembali lebih cepat dari yang keluar)
        $ccc = $this->cashConversionCycle($companyId);

        // 14. Jatuh tempo 7 hari ke depan
        $sevenDaysFrom = Carbon::now()->addDays(7)->toDateString();
        $upcomingAr = Invoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $sevenDaysFrom)
            ->orderBy('due_date')
            ->limit(10)
            ->get(['id', 'number', 'customer_name', 'due_date', 'total', 'paid_amount']);

        $upcomingAp = Bill::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['received', 'partial', 'overdue'])
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $sevenDaysFrom)
            ->orderBy('due_date')
            ->limit(10)
            ->get(['id', 'number', 'supplier_name', 'due_date', 'total', 'paid_amount']);

        return $this->success([
            'cash_position' => (float) $cashPosition,
            'ar_total' => (float) $arTotal,
            'ap_total' => (float) $apTotal,
            'revenue_mtd' => (float) $revenueMtd,
            'expense_mtd' => (float) $expenseMtd,
            'reimburse_pending_settle' => (int) $reimbursePending,
            'recent_journals' => $recentJournals,
            'ar_aging' => $arAging,
            'ap_aging' => $apAging,
            'cashflow_weekly' => $cashflowWeekly,
            'top_customers' => $topCustomers,
            'top_suppliers' => $topSuppliers,
            'upcoming_ar' => $upcomingAr,
            'upcoming_ap' => $upcomingAp,
            'ccc' => $ccc,
            'month_label' => Carbon::now()->translatedFormat('F Y'),
            'today' => $today,
        ]);
    }

    /**
     * Cash Conversion Cycle calculation untuk Finance Dashboard.
     * Pakai data 90 hari terakhir biar representatif (smooths out outliers).
     */
    private function cashConversionCycle(int $companyId): array
    {
        $since = Carbon::now()->subDays(90)->toDateString();

        // DSO: Days Sales Outstanding - avg invoice age sampai dibayar
        $paidInvoices = Invoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $since)
            ->get(['date', 'paid_at']);

        $dsoDays = 0;
        $invoicesCount = $paidInvoices->count();
        if ($invoicesCount > 0) {
            $totalDays = $paidInvoices->sum(function ($inv) {
                $issued = Carbon::parse($inv->date);
                $paid = Carbon::parse($inv->paid_at);
                return max(0, $issued->diffInDays($paid));
            });
            $dsoDays = round($totalDays / $invoicesCount, 1);
        }

        // DPO: Days Payable Outstanding - avg bill age sampai kita bayar
        $paidBills = Bill::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $since)
            ->get(['date', 'paid_at']);

        $dpoDays = 0;
        $billsCount = $paidBills->count();
        if ($billsCount > 0) {
            $totalDays = $paidBills->sum(function ($bill) {
                $issued = Carbon::parse($bill->date);
                $paid = Carbon::parse($bill->paid_at);
                return max(0, $issued->diffInDays($paid));
            });
            $dpoDays = round($totalDays / $billsCount, 1);
        }

        // CCC = DSO - DPO (since we skip DIO).
        // Positive = cash terikat lebih lama dari hutang. Negatif = vendor financing.
        $ccc = round($dsoDays - $dpoDays, 1);

        // Health label
        $label = $ccc < 0 ? 'excellent'
            : ($ccc <= 15 ? 'healthy'
            : ($ccc <= 30 ? 'fair' : 'needs_attention'));

        return [
            'dso'        => (float) $dsoDays,
            'dpo'        => (float) $dpoDays,
            'ccc'        => (float) $ccc,
            'label'      => $label,
            'sample_invoices' => $invoicesCount,
            'sample_bills'    => $billsCount,
            'period_days'     => 90,
        ];
    }

    /**
     * Macro indicators - BI Rate, Inflasi, IHSG. Cached.
     * BI Rate + Inflasi: hard-coded constants (update manual saat ada perubahan).
     * IHSG: Yahoo Finance v8 free endpoint (^JKSE).
     */
    public function macro(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'finance.module.access');

        // BI Rate + Inflasi - hard-coded snapshot (update saat ada perubahan rapat BI).
        // Source: bi.go.id (RDG hasil bulanan), bps.go.id (BPS inflasi YoY).
        // TODO: pindah ke DB settings supaya admin bisa edit tanpa redeploy.
        $biRate = [
            'value' => 5.75,
            'as_of' => '2026-04-23',  // tanggal RDG terakhir
            'source' => 'Bank Indonesia',
        ];
        $inflasi = [
            'value' => 2.10,
            'as_of' => '2026-04-30',
            'period' => 'Apr 2026 YoY',
            'source' => 'BPS',
        ];

        // IHSG - Yahoo Finance public quote (cache 30 menit)
        $ihsg = \Illuminate\Support\Facades\Cache::remember('finance.ihsg', 1800, function () {
            try {
                $res = \Illuminate\Support\Facades\Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (IBE Finance Dashboard)'])
                    ->get('https://query1.finance.yahoo.com/v8/finance/chart/%5EJKSE?interval=1d&range=5d');
                if (!$res->ok()) return null;
                $data = $res->json();
                $result = $data['chart']['result'][0] ?? null;
                if (!$result) return null;
                $meta = $result['meta'];
                $current = (float) ($meta['regularMarketPrice'] ?? 0);
                $previous = (float) ($meta['chartPreviousClose'] ?? $meta['previousClose'] ?? $current);
                $change = $current - $previous;
                $pct = $previous > 0 ? ($change / $previous * 100) : 0;
                return [
                    'value' => round($current, 2),
                    'change' => round($change, 2),
                    'change_pct' => round($pct, 2),
                    'previous_close' => round($previous, 2),
                    'as_of' => date('Y-m-d H:i', $meta['regularMarketTime'] ?? time()),
                    'source' => 'Yahoo Finance (IDX)',
                ];
            } catch (\Throwable $e) {
                return null;
            }
        });

        return $this->success([
            'bi_rate' => $biRate,
            'inflasi' => $inflasi,
            'ihsg' => $ihsg,  // null kalau gagal fetch
        ]);
    }

    /**
     * 12 weeks of cash in vs cash out, dari posted journals.
     * Cash in = credit di akun expense atau equity, atau debit di Kas/Bank dari source apapun
     *   → simplify: debit di Kas (1110) + Bank (1120) = cash masuk
     *   → credit di Kas + Bank = cash keluar
     */
    private function cashflowWeekly(int $companyId): array
    {
        $cashAccountIds = Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('code', ['1110', '1120'])
            ->pluck('id');
        if ($cashAccountIds->isEmpty()) {
            return [];
        }

        $startDate = Carbon::now()->startOfWeek(Carbon::MONDAY)->subWeeks(11);

        $rows = DB::table('fin_journal_lines AS jl')
            ->join('fin_journals AS j', 'j.id', '=', 'jl.journal_id')
            ->where('j.company_id', $companyId)
            ->where('j.status', 'posted')
            ->where('j.date', '>=', $startDate->toDateString())
            ->whereIn('jl.account_id', $cashAccountIds)
            ->selectRaw("YEARWEEK(j.date, 1) AS yw, COALESCE(SUM(jl.debit), 0) AS cash_in, COALESCE(SUM(jl.credit), 0) AS cash_out")
            ->groupBy('yw')
            ->get()
            ->keyBy('yw');

        $weeks = [];
        for ($i = 0; $i < 12; $i++) {
            $weekStart = $startDate->copy()->addWeeks($i);
            $yw = (int) $weekStart->format('oW');
            $weeks[] = [
                'week_start' => $weekStart->toDateString(),
                'label' => $weekStart->isoFormat('DD MMM'),
                'cash_in' => (float) ($rows[$yw]->cash_in ?? 0),
                'cash_out' => (float) ($rows[$yw]->cash_out ?? 0),
                'net' => (float) (($rows[$yw]->cash_in ?? 0) - ($rows[$yw]->cash_out ?? 0)),
            ];
        }
        return $weeks;
    }

    /**
     * Compute account balance dari posted journals:
     *   asset/expense: balance = sum(debit) - sum(credit)
     *   liability/equity/revenue: balance = sum(credit) - sum(debit)
     * Untuk Kas/Bank (asset) → debit - credit.
     */
    private function accountBalance(int $companyId, array $codes): float
    {
        $accountIds = Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('code', $codes)
            ->pluck('id');
        if ($accountIds->isEmpty()) return 0.0;

        $row = DB::table('fin_journal_lines AS jl')
            ->join('fin_journals AS j', 'j.id', '=', 'jl.journal_id')
            ->where('j.company_id', $companyId)
            ->where('j.status', 'posted')
            ->whereIn('jl.account_id', $accountIds)
            ->selectRaw('COALESCE(SUM(jl.debit), 0) AS dr, COALESCE(SUM(jl.credit), 0) AS cr')
            ->first();
        return (float) ($row->dr - $row->cr);
    }

    /**
     * Bucket aging berdasarkan umur dari tanggal dokumen.
     * Returns: ['current'=>x, '1_30'=>y, '31_60'=>z, '61_90'=>w, 'over_90'=>v]
     */
    private function agingBuckets(\Illuminate\Database\Eloquent\Collection $records, string $dateField): array
    {
        $buckets = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];
        $now = Carbon::now();
        foreach ($records as $r) {
            $remaining = (float) $r->total - (float) $r->paid_amount;
            if ($remaining <= 0) continue;
            $age = Carbon::parse($r->$dateField)->diffInDays($now);
            if ($age <= 0) $buckets['current'] += $remaining;
            elseif ($age <= 30) $buckets['1_30'] += $remaining;
            elseif ($age <= 60) $buckets['31_60'] += $remaining;
            elseif ($age <= 90) $buckets['61_90'] += $remaining;
            else $buckets['over_90'] += $remaining;
        }
        return $buckets;
    }
}
