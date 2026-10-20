<?php

namespace App\Keuangan\Http\Controllers;

use App\Keuangan\Services\ReportService;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends ApiController
{
    public function __construct(private readonly ReportService $service) {}

    /** GET /api/v1/finance/reports/ledger/{accountId}?from=&to= */
    public function ledger(Request $request, int $accountId): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');
        $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);
        return $this->success($this->service->generalLedger(
            $request->user(),
            $accountId,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        ));
    }

    /** GET /api/v1/finance/reports/trial-balance?as_of= */
    public function trialBalance(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');
        $request->validate(['as_of' => ['nullable', 'date']]);
        return $this->success($this->service->trialBalance(
            $request->user(),
            $request->string('as_of')->toString() ?: null,
        ));
    }

    /** GET /api/v1/finance/reports/balance-sheet?as_of= */
    public function balanceSheet(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');
        $request->validate(['as_of' => ['nullable', 'date']]);
        return $this->success($this->service->balanceSheet(
            $request->user(),
            $request->string('as_of')->toString() ?: null,
        ));
    }

    /** GET /api/v1/finance/reports/profit-loss?from=&to= */
    public function profitLoss(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');
        $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);
        return $this->success($this->service->profitLoss(
            $request->user(),
            $request->string('from')->toString(),
            $request->string('to')->toString(),
        ));
    }

    /** GET /api/v1/finance/reports/cash-flow?from=&to= */
    public function cashFlow(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');
        $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);
        return $this->success($this->service->cashFlow(
            $request->user(),
            $request->string('from')->toString(),
            $request->string('to')->toString(),
        ));
    }

    /** GET /api/v1/finance/reports/ar-aging?as_of= */
    public function arAging(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');
        $request->validate(['as_of' => ['nullable', 'date']]);
        return $this->success($this->service->arAging(
            $request->user(),
            $request->string('as_of')->toString() ?: null,
        ));
    }

    /** GET /api/v1/finance/reports/ap-aging?as_of= */
    public function apAging(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');
        $request->validate(['as_of' => ['nullable', 'date']]);
        return $this->success($this->service->apAging(
            $request->user(),
            $request->string('as_of')->toString() ?: null,
        ));
    }

    /** GET /api/v1/finance/reports/tax-ppn?from=&to= */
    public function taxPpn(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');
        $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);
        return $this->success($this->service->taxPpnSummary(
            $request->user(),
            $request->string('from')->toString(),
            $request->string('to')->toString(),
        ));
    }

    /**
     * GET /api/v1/finance/reports/export/{type}
     *
     * Mengekspor laporan yang sama persis dengan yang tampil di layar - memakai
     * ulang ReportService, bukan menghitung ulang, supaya angka di berkas tidak
     * pernah berbeda dengan angka di aplikasi.
     *
     * Pemisah kolom memakai titik koma karena Excel dengan lokal Indonesia
     * membuka CSV berkoma sebagai satu kolom. BOM UTF-8 ditambahkan agar nama
     * akun berhuruf non-ASCII tidak rusak di Excel.
     */
    public function export(Request $request, string $type)
    {
        $this->requireCapability($request->user(), 'finance.module.access');

        $u = $request->user();
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;
        $asOf = $request->string('as_of')->toString() ?: null;
        $defFrom = $from ?: '1970-01-01';
        $defTo = $to ?: now()->toDateString();

        [$judul, $data] = match ($type) {
            'trial-balance' => ['Neraca Saldo', $this->service->trialBalance($u, $asOf)],
            'balance-sheet' => ['Neraca', $this->service->balanceSheet($u, $asOf)],
            'profit-loss' => ['Laba Rugi', $this->service->profitLoss($u, $defFrom, $defTo)],
            'cash-flow' => ['Arus Kas', $this->service->cashFlow($u, $defFrom, $defTo)],
            'ar-aging' => ['Aging Piutang (AR)', $this->service->arAging($u, $asOf)],
            'ap-aging' => ['Aging Utang (AP)', $this->service->apAging($u, $asOf)],
            'tax-ppn' => ['Pajak PPN (VAT)', $this->service->taxPpnSummary($u, $defFrom, $defTo)],
            'ledger' => ['Buku Besar', $this->service->generalLedger($u, (int) $request->integer('account_id'), $from, $to)],
            default => abort(404, 'Jenis laporan tidak dikenal.'),
        };

        $rows = array_merge(
            [[$judul], ['Diekspor', now()->format('d/m/Y H:i')], []],
            $this->csvRows(is_array($data) ? $data : (array) $data)
        );

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $r) {
            fputcsv($out, $r, ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $nama = 'laporan-' . $type . '-' . now()->format('Ymd-Hi') . '.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $nama . '"',
        ]);
    }

    /**
     * Istilah kolom laporan dalam bahasa Indonesia.
     *
     * Perataan CSV bersifat generik dan memakai kunci respons apa adanya, yang
     * sebagian masih berbahasa Inggris. Berkas ini dipakai bagian keuangan, jadi
     * kuncinya diterjemahkan di sini - kunci yang belum terdaftar tetap tampil
     * apa adanya, bukan gagal.
     */
    private const ISTILAH = [
        'as_of' => 'Per tanggal', 'period' => 'Periode', 'from' => 'Dari', 'to' => 'Sampai',
        'accounts' => 'Akun', 'account' => 'Akun', 'account_id' => 'ID akun', 'id' => 'ID',
        'code' => 'Kode', 'name' => 'Nama', 'type' => 'Jenis', 'normal_balance' => 'Saldo normal',
        'balance' => 'Saldo', 'total_debit' => 'Total debit', 'total_credit' => 'Total kredit',
        'is_balanced' => 'Seimbang', 'debit' => 'Debit', 'credit' => 'Kredit',
        'entries' => 'Transaksi', 'rows' => 'Rincian',
        'opening_balance' => 'Saldo awal', 'ending_balance' => 'Saldo akhir',
        'closing_balance' => 'Saldo akhir', 'date' => 'Tanggal', 'number' => 'Nomor',
        'description' => 'Keterangan', 'reference' => 'Referensi', 'memo' => 'Memo',
        'amount' => 'Jumlah', 'status' => 'Status', 'is_profit' => 'Laba', 'net' => 'Neto',
        'in' => 'Masuk', 'out' => 'Keluar', 'inflows' => 'Arus masuk', 'outflows' => 'Arus keluar',
        'total_in' => 'Total masuk', 'total_out' => 'Total keluar', 'cash_accounts' => 'Akun kas',
        'by_customer' => 'Per pelanggan', 'by_supplier' => 'Per pemasok',
        'customer' => 'Pelanggan', 'supplier' => 'Pemasok',
        'buckets' => 'Umur', 'current' => 'Belum jatuh tempo',
        'total_outstanding' => 'Total tunggakan', 'outstanding' => 'Tunggakan',
        'input_vat' => 'PPN masukan', 'output_vat' => 'PPN keluaran',
        'count' => 'Jumlah dokumen', 'total_dpp' => 'Total DPP', 'total_vat' => 'Total PPN',
        'bills' => 'Tagihan', 'invoices' => 'Faktur',
        'net_vat_payable' => 'PPN kurang/(lebih) bayar',
    ];

    /** Nilai enum yang tersimpan dalam bahasa Inggris. */
    private const NILAI_ENUM = [
        'asset' => 'Aset', 'liability' => 'Kewajiban', 'equity' => 'Modal',
        'revenue' => 'Pendapatan', 'expense' => 'Beban',
        'debit' => 'Debit', 'credit' => 'Kredit',
        'draft' => 'Draf', 'posted' => 'Diposting', 'reversed' => 'Dibalik',
        'kurang_bayar' => 'Kurang bayar', 'lebih_bayar' => 'Lebih bayar', 'nihil' => 'Nihil',
    ];

    private function istilah(string $k): string
    {
        $bersih = strtolower(str_replace(chr(45), '_', $k));

        return self::ISTILAH[$bersih] ?? ucfirst(str_replace('_', ' ', $k));
    }

    /**
     * Meratakan struktur laporan menjadi baris CSV.
     *
     * Ditulis generik dengan sengaja: bentuk tiap laporan berbeda-beda, dan
     * pemetaan manual per laporan akan diam-diam kehilangan kolom begitu bentuk
     * responsnya berubah.
     */
    private function csvRows(array $data, string $prefix = ''): array
    {
        $skalar = [];
        $tabel = [];

        foreach ($data as $k => $v) {
            $seg = $this->istilah((string) $k);
            $label = $prefix === '' ? $seg : $prefix . ' / ' . $seg;
            $labelRapi = $label;

            if (is_array($v) && $v !== [] && array_keys($v) === range(0, count($v) - 1) && is_array($v[0])) {
                $tabel[] = [];
                $tabel[] = [mb_strtoupper($label)];
                $kolom = array_keys($v[0]);
                $tabel[] = array_map(fn ($h) => $this->istilah((string) $h), $kolom);
                foreach ($v as $item) {
                    $tabel[] = array_map(fn ($h) => $this->csvNilai($item[$h] ?? ''), $kolom);
                }
            } elseif (is_array($v) && $v !== []) {
                $tabel = array_merge($tabel, $this->csvRows($v, $label));
            } elseif (is_array($v)) {
                $skalar[] = [$labelRapi, '(kosong)'];
            } else {
                $skalar[] = [$labelRapi, $this->csvNilai($v)];
            }
        }

        return array_merge($skalar, $tabel);
    }

    private function csvNilai($v): string
    {
        if (is_bool($v)) {
            return $v ? 'ya' : 'tidak';
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        $teks = (string) ($v ?? '');

        return self::NILAI_ENUM[strtolower($teks)] ?? $teks;
    }
}
