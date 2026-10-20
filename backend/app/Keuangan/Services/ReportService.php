<?php

namespace App\Keuangan\Services;

use App\Keuangan\Models\Account;
use App\Keuangan\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Financial reports - all derived dari fin_journals + fin_journal_lines.
 * Hanya posted journals yang masuk laporan (draft & reversed di-exclude).
 *
 * - generalLedger($accountId, $from, $to)  - per-account transaction history
 * - trialBalance($asOf)                    - semua akun: total debit + credit
 * - balanceSheet($asOf)                    - Aset = Kewajiban + Modal
 * - profitLoss($from, $to)                 - Pendapatan - Beban
 */
class ReportService
{
    /**
     * General Ledger (Buku Besar) per account dengan running balance.
     * Sesuai normal_balance: asset/expense = (debit - credit), lainnya = (credit - debit).
     */
    public function generalLedger(User $admin, int $accountId, ?string $from, ?string $to): array
    {
        $account = Account::query()
            ->where('company_id', $admin->company_id)
            ->findOrFail($accountId);

        $query = JournalLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journal', fn ($q) => $q
                ->where('company_id', $admin->company_id)
                ->whereIn('status', ['posted', 'reversed'])
                ->when($from, fn ($w) => $w->whereDate('date', '>=', $from))
                ->when($to, fn ($w) => $w->whereDate('date', '<=', $to))
            )
            ->with('journal:id,number,date,description,reference')
            ->orderBy(
                JournalLine::query()
                    ->getModel()
                    ->journal()
                    ->getRelated()
                    ->newQuery()
                    ->select('date')
                    ->whereColumn('fin_journals.id', 'fin_journal_lines.journal_id')
            )
            ->get();

        // Sort lebih sederhana - pakai eager-loaded relation
        $rows = $query
            ->sortBy(fn ($l) => $l->journal?->date?->format('YmdHis') . str_pad((string) $l->id, 30, '0', STR_PAD_LEFT))
            ->values();

        $normalBalance = $account->normal_balance;
        $running = 0.0;
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        $entries = $rows->map(function ($l) use (&$running, &$totalDebit, &$totalCredit, $normalBalance) {
            $d = (float) $l->debit;
            $c = (float) $l->credit;
            $totalDebit += $d;
            $totalCredit += $c;
            // Running balance per normal balance
            if ($normalBalance === 'debit') {
                $running += $d - $c;
            } else {
                $running += $c - $d;
            }
            return [
                'date'         => $l->journal?->date?->toDateString(),
                'number'       => $l->journal?->number,
                'description'  => $l->journal?->description,
                'reference'    => $l->journal?->reference,
                'memo'         => $l->memo,
                'debit'        => $d,
                'credit'       => $c,
                'balance'      => round($running, 2),
            ];
        });

        return [
            'account' => [
                'id'             => $account->id,
                'code'           => $account->code,
                'name'           => $account->name,
                'type'           => $account->type,
                'normal_balance' => $account->normal_balance,
            ],
            'period'         => ['from' => $from, 'to' => $to],
            'entries'        => $entries->all(),
            'total_debit'    => round($totalDebit, 2),
            'total_credit'   => round($totalCredit, 2),
            'ending_balance' => round($running, 2),
        ];
    }

    /**
     * Trial Balance - semua akun dengan total debit + credit + saldo akhir.
     */
    public function trialBalance(User $admin, ?string $asOf): array
    {
        $asOf = $asOf ?: now()->toDateString();

        $rows = DB::table('fin_journal_lines as l')
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.company_id', $admin->company_id)
            ->whereIn('j.status', ['posted', 'reversed'])
            ->whereDate('j.date', '<=', $asOf)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance')
            ->orderBy('a.code')
            ->select(
                'a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance',
                DB::raw('SUM(l.debit) as total_debit'),
                DB::raw('SUM(l.credit) as total_credit'),
            )
            ->get();

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $accounts = $rows->map(function ($r) use (&$totalDebit, &$totalCredit) {
            $d = (float) $r->total_debit;
            $c = (float) $r->total_credit;
            $balance = $r->normal_balance === 'debit' ? ($d - $c) : ($c - $d);
            $totalDebit += $d;
            $totalCredit += $c;
            return [
                'id'             => $r->id,
                'code'           => $r->code,
                'name'           => $r->name,
                'type'           => $r->type,
                'normal_balance' => $r->normal_balance,
                'total_debit'    => round($d, 2),
                'total_credit'   => round($c, 2),
                'balance'        => round($balance, 2),
            ];
        });

        return [
            'as_of'        => $asOf,
            'accounts'     => $accounts->all(),
            'total_debit'  => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'is_balanced'  => abs($totalDebit - $totalCredit) < 0.01,
        ];
    }

    /**
     * Neraca / Balance Sheet - Aset = Kewajiban + Modal
     */
    public function balanceSheet(User $admin, ?string $asOf): array
    {
        $trial = $this->trialBalance($admin, $asOf);
        $byType = collect($trial['accounts'])->groupBy('type');

        $asetItems       = $byType->get('asset', collect())->values();
        $kewajibanItems  = $byType->get('liability', collect())->values();
        $modalItems      = $byType->get('equity', collect())->values();
        // Laba/rugi periode = Pendapatan - Beban (masuk Modal)
        $pendapatanTotal = $byType->get('revenue', collect())->sum('balance');
        $bebanTotal      = $byType->get('expense', collect())->sum('balance');
        $labaRugiBerjalan = $pendapatanTotal - $bebanTotal;

        $totalAset      = collect($asetItems)->sum('balance');
        $totalKewajiban = collect($kewajibanItems)->sum('balance');
        $totalModal     = collect($modalItems)->sum('balance') + $labaRugiBerjalan;

        return [
            'as_of'                  => $trial['as_of'],
            'aset'                   => $asetItems->all(),
            'total_aset'             => round($totalAset, 2),
            'kewajiban'              => $kewajibanItems->all(),
            'total_kewajiban'        => round($totalKewajiban, 2),
            'modal'                  => $modalItems->all(),
            'laba_rugi_berjalan'     => round($labaRugiBerjalan, 2),
            'total_modal'            => round($totalModal, 2),
            'total_kewajiban_modal'  => round($totalKewajiban + $totalModal, 2),
            'is_balanced'            => abs($totalAset - ($totalKewajiban + $totalModal)) < 0.01,
        ];
    }

    /**
     * Laba Rugi (Profit & Loss) untuk periode tertentu.
     */
    public function profitLoss(User $admin, string $from, string $to): array
    {
        // Trial balance untuk periode (revenue & expense saldo periode-only)
        $rows = DB::table('fin_journal_lines as l')
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.company_id', $admin->company_id)
            ->whereIn('j.status', ['posted', 'reversed'])
            ->whereBetween('j.date', [$from, $to])
            ->whereIn('a.type', ['revenue', 'expense'])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance')
            ->orderBy('a.code')
            ->select(
                'a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance',
                DB::raw('SUM(l.debit) as total_debit'),
                DB::raw('SUM(l.credit) as total_credit'),
            )
            ->get();

        $pendapatan = [];
        $beban = [];
        foreach ($rows as $r) {
            $d = (float) $r->total_debit;
            $c = (float) $r->total_credit;
            $balance = $r->normal_balance === 'debit' ? ($d - $c) : ($c - $d);
            $item = [
                'id'      => $r->id,
                'code'    => $r->code,
                'name'    => $r->name,
                'balance' => round($balance, 2),
            ];
            if ($r->type === 'revenue') $pendapatan[] = $item;
            else $beban[] = $item;
        }

        $totalPendapatan = array_sum(array_column($pendapatan, 'balance'));
        $totalBeban      = array_sum(array_column($beban, 'balance'));
        $laba            = $totalPendapatan - $totalBeban;

        return [
            'period'          => ['from' => $from, 'to' => $to],
            'pendapatan'      => $pendapatan,
            'total_pendapatan'=> round($totalPendapatan, 2),
            'beban'           => $beban,
            'total_beban'     => round($totalBeban, 2),
            'laba_rugi'       => round($laba, 2),
            'is_profit'       => $laba >= 0,
        ];
    }

    /**
     * Tax (PPN) Summary Report - Indonesian VAT 11%.
     *
     * Pajak Keluaran (Output VAT) - dari fin_invoices.tax_amount untuk invoice yang sent/paid.
     * Pajak Masukan (Input VAT)   - dari fin_bills.tax_amount untuk bill yang received/paid.
     *
     * Net VAT Payable = Output - Input (kalau positif, bayar ke kas negara;
     * kalau negatif, lebih bayar - bisa restitusi atau dikompensasi periode berikutnya).
     */
    public function taxPpnSummary(User $admin, string $from, string $to): array
    {
        // Output VAT (Pajak Keluaran) dari invoice
        $invoices = DB::table('fin_invoices')
            ->where('company_id', $admin->company_id)
            ->whereIn('status', ['sent', 'partially_paid', 'paid'])
            ->whereBetween('date', [$from, $to])
            ->where('tax_amount', '>', 0)
            ->select('id', 'number', 'date', 'customer_id', 'subtotal', 'tax_rate', 'tax_amount', 'total', 'status')
            ->orderBy('date')
            ->get();

        // Input VAT (Pajak Masukan) dari bills
        $bills = DB::table('fin_bills')
            ->where('company_id', $admin->company_id)
            ->whereIn('status', ['received', 'partially_paid', 'paid'])
            ->whereBetween('date', [$from, $to])
            ->where('tax_amount', '>', 0)
            ->select('id', 'number', 'date', 'supplier_id', 'subtotal', 'tax_rate', 'tax_amount', 'total', 'status')
            ->orderBy('date')
            ->get();

        $outputTotal = (float) $invoices->sum('tax_amount');
        $outputDpp   = (float) $invoices->sum('subtotal');
        $inputTotal  = (float) $bills->sum('tax_amount');
        $inputDpp    = (float) $bills->sum('subtotal');
        $netPayable  = $outputTotal - $inputTotal;

        // Resolve customer/supplier names
        $customerIds = $invoices->pluck('customer_id')->filter()->unique();
        $supplierIds = $bills->pluck('supplier_id')->filter()->unique();

        $customers = DB::table('fin_customers')
            ->whereIn('id', $customerIds)->pluck('name', 'id')->all();
        $suppliers = DB::table('fin_suppliers')
            ->whereIn('id', $supplierIds)->pluck('name', 'id')->all();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'output_vat' => [
                'count'        => $invoices->count(),
                'total_dpp'    => round($outputDpp, 2),       // Dasar Pengenaan Pajak (base)
                'total_vat'    => round($outputTotal, 2),     // VAT amount
                'invoices'     => $invoices->map(fn ($i) => [
                    'id'            => $i->id,
                    'number'        => $i->number,
                    'date'          => $i->date,
                    'customer_name' => $customers[$i->customer_id] ?? '(no customer)',
                    'dpp'           => round((float) $i->subtotal, 2),
                    'tax_rate'      => (float) $i->tax_rate,
                    'tax_amount'    => round((float) $i->tax_amount, 2),
                    'total'         => round((float) $i->total, 2),
                    'status'        => $i->status,
                ])->values(),
            ],
            'input_vat' => [
                'count'        => $bills->count(),
                'total_dpp'    => round($inputDpp, 2),
                'total_vat'    => round($inputTotal, 2),
                'bills'        => $bills->map(fn ($b) => [
                    'id'            => $b->id,
                    'number'        => $b->number,
                    'date'          => $b->date,
                    'supplier_name' => $suppliers[$b->supplier_id] ?? '(no supplier)',
                    'dpp'           => round((float) $b->subtotal, 2),
                    'tax_rate'      => (float) $b->tax_rate,
                    'tax_amount'    => round((float) $b->tax_amount, 2),
                    'total'         => round((float) $b->total, 2),
                    'status'        => $b->status,
                ])->values(),
            ],
            'net_vat_payable' => round($netPayable, 2),
            'status'          => $netPayable >= 0 ? 'kurang_bayar' : 'lebih_bayar',
        ];
    }

    /**
     * AR Aging - outstanding receivables (invoice unpaid) bucketed by overdue age.
     *
     * Buckets: current (not yet due), 1-30 hari overdue, 31-60, 61-90, >90.
     */
    public function arAging(User $admin, ?string $asOf = null): array
    {
        $today = $asOf ? \Carbon\Carbon::parse($asOf) : \Carbon\Carbon::today();
        $todayStr = $today->toDateString();

        $rows = DB::table('fin_invoices as i')
            ->leftJoin('fin_customers as c', 'c.id', '=', 'i.customer_id')
            ->where('i.company_id', $admin->company_id)
            ->whereIn('i.status', ['sent', 'partially_paid', 'overdue'])
            ->select(
                'i.id', 'i.number', 'i.date', 'i.due_date',
                'i.total', 'i.paid_amount',
                'c.id as customer_id', 'c.name as customer_name',
            )
            ->get();

        $result = [
            'as_of'   => $todayStr,
            'buckets' => ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '>90' => 0],
            'rows'    => [],
            'by_customer' => [],
            'total_outstanding' => 0,
        ];

        $byCust = [];
        foreach ($rows as $r) {
            $outstanding = (float) $r->total - (float) $r->paid_amount;
            if ($outstanding <= 0.001) continue;

            $due = \Carbon\Carbon::parse($r->due_date);
            $overdueDays = (int) $due->diffInDays($today, false);

            $bucket = $overdueDays < 0 ? 'current'
                : ($overdueDays <= 30 ? '1-30'
                : ($overdueDays <= 60 ? '31-60'
                : ($overdueDays <= 90 ? '61-90' : '>90')));

            $result['buckets'][$bucket] += $outstanding;
            $result['total_outstanding'] += $outstanding;
            $result['rows'][] = [
                'id'           => $r->id,
                'number'       => $r->number,
                'date'         => $r->date,
                'due_date'     => $r->due_date,
                'customer_id'  => $r->customer_id,
                'customer_name'=> $r->customer_name,
                'total'        => round((float) $r->total, 2),
                'paid_amount'  => round((float) $r->paid_amount, 2),
                'outstanding'  => round($outstanding, 2),
                'overdue_days' => $overdueDays,
                'bucket'       => $bucket,
            ];
            $cid = $r->customer_id ?? 0;
            if (!isset($byCust[$cid])) {
                $byCust[$cid] = [
                    'customer_id'   => $r->customer_id,
                    'customer_name' => $r->customer_name ?? '(no customer)',
                    'current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '>90' => 0,
                    'total'   => 0,
                    'count'   => 0,
                ];
            }
            $byCust[$cid][$bucket] += $outstanding;
            $byCust[$cid]['total']  += $outstanding;
            $byCust[$cid]['count']  += 1;
        }

        // Round + sort
        foreach ($result['buckets'] as $k => $v) {
            $result['buckets'][$k] = round($v, 2);
        }
        $result['total_outstanding'] = round($result['total_outstanding'], 2);

        usort($result['rows'], fn ($a, $b) => $b['overdue_days'] <=> $a['overdue_days']);

        $byCustList = array_values($byCust);
        foreach ($byCustList as &$c) {
            foreach (['current','1-30','31-60','61-90','>90','total'] as $k) {
                $c[$k] = round($c[$k], 2);
            }
        }
        usort($byCustList, fn ($a, $b) => $b['total'] <=> $a['total']);
        $result['by_customer'] = $byCustList;

        return $result;
    }

    /**
     * AP Aging - outstanding payables (bill unpaid) bucketed by overdue age.
     */
    public function apAging(User $admin, ?string $asOf = null): array
    {
        $today = $asOf ? \Carbon\Carbon::parse($asOf) : \Carbon\Carbon::today();
        $todayStr = $today->toDateString();

        $rows = DB::table('fin_bills as b')
            ->leftJoin('fin_suppliers as s', 's.id', '=', 'b.supplier_id')
            ->where('b.company_id', $admin->company_id)
            ->whereIn('b.status', ['received', 'partially_paid', 'overdue'])
            ->select(
                'b.id', 'b.number', 'b.date', 'b.due_date',
                'b.total', 'b.paid_amount',
                's.id as supplier_id', 's.name as supplier_name',
            )
            ->get();

        $result = [
            'as_of'   => $todayStr,
            'buckets' => ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '>90' => 0],
            'rows'    => [],
            'by_supplier' => [],
            'total_outstanding' => 0,
        ];

        $bySup = [];
        foreach ($rows as $r) {
            $outstanding = (float) $r->total - (float) $r->paid_amount;
            if ($outstanding <= 0.001) continue;

            $due = \Carbon\Carbon::parse($r->due_date);
            $overdueDays = (int) $due->diffInDays($today, false);

            $bucket = $overdueDays < 0 ? 'current'
                : ($overdueDays <= 30 ? '1-30'
                : ($overdueDays <= 60 ? '31-60'
                : ($overdueDays <= 90 ? '61-90' : '>90')));

            $result['buckets'][$bucket] += $outstanding;
            $result['total_outstanding'] += $outstanding;
            $result['rows'][] = [
                'id'           => $r->id,
                'number'       => $r->number,
                'date'         => $r->date,
                'due_date'     => $r->due_date,
                'supplier_id'  => $r->supplier_id,
                'supplier_name'=> $r->supplier_name,
                'total'        => round((float) $r->total, 2),
                'paid_amount'  => round((float) $r->paid_amount, 2),
                'outstanding'  => round($outstanding, 2),
                'overdue_days' => $overdueDays,
                'bucket'       => $bucket,
            ];
            $sid = $r->supplier_id ?? 0;
            if (!isset($bySup[$sid])) {
                $bySup[$sid] = [
                    'supplier_id'   => $r->supplier_id,
                    'supplier_name' => $r->supplier_name ?? '(no supplier)',
                    'current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '>90' => 0,
                    'total'   => 0,
                    'count'   => 0,
                ];
            }
            $bySup[$sid][$bucket] += $outstanding;
            $bySup[$sid]['total']  += $outstanding;
            $bySup[$sid]['count']  += 1;
        }

        foreach ($result['buckets'] as $k => $v) {
            $result['buckets'][$k] = round($v, 2);
        }
        $result['total_outstanding'] = round($result['total_outstanding'], 2);

        usort($result['rows'], fn ($a, $b) => $b['overdue_days'] <=> $a['overdue_days']);

        $bySupList = array_values($bySup);
        foreach ($bySupList as &$s) {
            foreach (['current','1-30','31-60','61-90','>90','total'] as $k) {
                $s[$k] = round($s[$k], 2);
            }
        }
        usort($bySupList, fn ($a, $b) => $b['total'] <=> $a['total']);
        $result['by_supplier'] = $bySupList;

        return $result;
    }

    /**
     * Cash Flow Statement (simplified - indirect-derived from journal lines on cash/bank accounts).
     *
     * Returns: opening + closing cash balance, total in/out, breakdown by counter account.
     * Cash accounts diidentifikasi sebagai akun dengan code 1110 (Kas) atau 1120 (Bank), atau subaccount-nya.
     */
    public function cashFlow(User $admin, string $from, string $to): array
    {
        $cashAccountIds = DB::table('fin_accounts')
            ->where('company_id', $admin->company_id)
            ->where(function ($q) {
                $q->where('code', 'like', '111%')->orWhere('code', 'like', '112%');
            })
            ->pluck('id', 'id')
            ->keys()
            ->all();

        if (empty($cashAccountIds)) {
            return [
                'period' => ['from' => $from, 'to' => $to],
                'opening_balance' => 0, 'closing_balance' => 0,
                'total_in' => 0, 'total_out' => 0, 'net' => 0,
                'inflows' => [], 'outflows' => [], 'cash_accounts' => [],
            ];
        }

        // Opening: sum of (debit - credit) on cash accounts before `from`
        $opening = (float) DB::table('fin_journal_lines as l')
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.company_id', $admin->company_id)
            ->whereIn('j.status', ['posted', 'reversed'])
            ->where('j.date', '<', $from)
            ->whereIn('l.account_id', $cashAccountIds)
            ->sum(DB::raw('l.debit - l.credit'));

        // Period cash movements: per cash account
        $periodMovement = DB::table('fin_journal_lines as l')
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.company_id', $admin->company_id)
            ->whereIn('j.status', ['posted', 'reversed'])
            ->whereBetween('j.date', [$from, $to])
            ->whereIn('l.account_id', $cashAccountIds)
            ->groupBy('a.id', 'a.code', 'a.name')
            ->select('a.id', 'a.code', 'a.name',
                DB::raw('SUM(l.debit) as total_in'),
                DB::raw('SUM(l.credit) as total_out'),
            )
            ->get();

        $cashAccounts = $periodMovement->map(fn ($r) => [
            'id'       => $r->id,
            'code'     => $r->code,
            'name'     => $r->name,
            'in'       => round((float) $r->total_in, 2),
            'out'      => round((float) $r->total_out, 2),
            'net'      => round((float) $r->total_in - (float) $r->total_out, 2),
        ])->all();

        $totalIn  = (float) array_sum(array_column($cashAccounts, 'in'));
        $totalOut = (float) array_sum(array_column($cashAccounts, 'out'));
        $net      = $totalIn - $totalOut;

        // Breakdown by counter account (which non-cash account moved against cash)
        // Strategy: for each journal that has a cash line, look at OTHER lines in same journal.
        $journalIds = DB::table('fin_journal_lines as l')
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.company_id', $admin->company_id)
            ->whereIn('j.status', ['posted', 'reversed'])
            ->whereBetween('j.date', [$from, $to])
            ->whereIn('l.account_id', $cashAccountIds)
            ->distinct()
            ->pluck('l.journal_id');

        $counterRows = DB::table('fin_journal_lines as l')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('l.journal_id', $journalIds)
            ->whereNotIn('l.account_id', $cashAccountIds)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->select('a.id', 'a.code', 'a.name', 'a.type',
                DB::raw('SUM(l.debit) as total_debit'),
                DB::raw('SUM(l.credit) as total_credit'),
            )
            ->get();

        // If counter account was DEBITED (e.g. expense, asset purchase), it means cash went OUT
        // If counter account was CREDITED (e.g. revenue, sale, loan received), it means cash came IN
        $inflows = [];
        $outflows = [];
        foreach ($counterRows as $r) {
            $debited  = (float) $r->total_debit;
            $credited = (float) $r->total_credit;
            // Net direction: if credited > debited, cash came IN (against that counter)
            if ($credited > $debited) {
                $inflows[] = [
                    'code' => $r->code, 'name' => $r->name, 'type' => $r->type,
                    'amount' => round($credited - $debited, 2),
                ];
            } elseif ($debited > $credited) {
                $outflows[] = [
                    'code' => $r->code, 'name' => $r->name, 'type' => $r->type,
                    'amount' => round($debited - $credited, 2),
                ];
            }
        }
        usort($inflows, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        usort($outflows, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'period'          => ['from' => $from, 'to' => $to],
            'opening_balance' => round($opening, 2),
            'total_in'        => round($totalIn, 2),
            'total_out'       => round($totalOut, 2),
            'net'             => round($net, 2),
            'closing_balance' => round($opening + $net, 2),
            'cash_accounts'   => $cashAccounts,
            'inflows'         => $inflows,
            'outflows'        => $outflows,
        ];
    }
}
