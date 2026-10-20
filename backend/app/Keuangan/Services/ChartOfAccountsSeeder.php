<?php

namespace App\Keuangan\Services;

use App\Keuangan\Models\Account;

/**
 * Seeder default Chart of Accounts standar PSAK Indonesia untuk SME/manufaktur.
 * Idempotent: pakai updateOrCreate berdasarkan (company_id, code).
 */
class ChartOfAccountsSeeder
{
    /**
     * Struktur akun standar:
     *   1xxx Aset, 2xxx Kewajiban, 3xxx Modal, 4xxx Pendapatan, 5xxx Beban
     *   [code, name, type, is_header, parent_code]
     */
    private const ACCOUNTS = [
        // ── 1xxx ASET ─────────────────────────────────────
        ['1000', 'ASET',                          'asset',     true,  null],
        ['1100', 'Aset Lancar',                    'asset',     true,  '1000'],
        ['1110', 'Kas',                            'asset',     false, '1100'],
        ['1120', 'Bank',                           'asset',     false, '1100'],
        ['1130', 'Piutang Usaha',                  'asset',     false, '1100'],
        ['1140', 'Persediaan',                     'asset',     false, '1100'],
        ['1150', 'Uang Muka / Biaya Dibayar Dimuka','asset',    false, '1100'],
        ['1200', 'Aset Tetap',                     'asset',     true,  '1000'],
        ['1210', 'Tanah',                          'asset',     false, '1200'],
        ['1220', 'Bangunan',                       'asset',     false, '1200'],
        ['1230', 'Kendaraan',                      'asset',     false, '1200'],
        ['1240', 'Peralatan & Mesin',              'asset',     false, '1200'],
        ['1250', 'Inventaris Kantor',              'asset',     false, '1200'],
        ['1290', 'Akumulasi Penyusutan',           'asset',     false, '1200'],

        // ── 2xxx KEWAJIBAN ────────────────────────────────
        ['2000', 'KEWAJIBAN',                      'liability', true,  null],
        ['2100', 'Kewajiban Lancar',               'liability', true,  '2000'],
        ['2110', 'Hutang Usaha',                   'liability', false, '2100'],
        ['2120', 'Hutang Gaji',                    'liability', false, '2100'],
        ['2130', 'Hutang Pajak (PPh21/PPN)',       'liability', false, '2100'],
        ['2140', 'Hutang BPJS Kesehatan',          'liability', false, '2100'],
        ['2150', 'Hutang BPJS Ketenagakerjaan',    'liability', false, '2100'],
        ['2160', 'Pendapatan Diterima Dimuka',     'liability', false, '2100'],
        ['2200', 'Kewajiban Jangka Panjang',       'liability', true,  '2000'],
        ['2210', 'Hutang Bank Jangka Panjang',     'liability', false, '2200'],
        ['2220', 'Hutang Leasing',                 'liability', false, '2200'],

        // ── 3xxx MODAL ────────────────────────────────────
        ['3000', 'MODAL',                          'equity',    true,  null],
        ['3100', 'Modal Disetor',                  'equity',    false, '3000'],
        ['3200', 'Laba Ditahan',                   'equity',    false, '3000'],
        ['3300', 'Laba/Rugi Tahun Berjalan',       'equity',    false, '3000'],
        ['3400', 'Prive / Penarikan Pemilik',      'equity',    false, '3000'],

        // ── 4xxx PENDAPATAN ───────────────────────────────
        ['4000', 'PENDAPATAN',                     'revenue',   true,  null],
        ['4100', 'Pendapatan Operasional',         'revenue',   true,  '4000'],
        ['4110', 'Penjualan Produk',               'revenue',   false, '4100'],
        ['4120', 'Pendapatan Jasa',                'revenue',   false, '4100'],
        ['4130', 'Diskon Penjualan',               'revenue',   false, '4100'],
        ['4200', 'Pendapatan Non-Operasional',     'revenue',   true,  '4000'],
        ['4210', 'Pendapatan Bunga Bank',          'revenue',   false, '4200'],
        ['4220', 'Pendapatan Lain-lain',           'revenue',   false, '4200'],

        // ── 5xxx BEBAN ────────────────────────────────────
        ['5000', 'BEBAN',                          'expense',   true,  null],
        ['5100', 'Beban Pokok Penjualan (HPP)',    'expense',   true,  '5000'],
        ['5110', 'HPP - Material',                 'expense',   false, '5100'],
        ['5120', 'HPP - Tenaga Kerja Langsung',    'expense',   false, '5100'],
        ['5130', 'HPP - Overhead Produksi',        'expense',   false, '5100'],
        ['5200', 'Beban Operasional',              'expense',   true,  '5000'],
        ['5210', 'Beban Gaji & Tunjangan',         'expense',   false, '5200'],
        ['5220', 'Beban BPJS (employer share)',    'expense',   false, '5200'],
        ['5230', 'Beban Listrik & Air',            'expense',   false, '5200'],
        ['5240', 'Beban Internet & Telepon',       'expense',   false, '5200'],
        ['5250', 'Beban Sewa Kantor',              'expense',   false, '5200'],
        ['5260', 'Beban Perlengkapan Kantor',      'expense',   false, '5200'],
        ['5270', 'Beban Transportasi & Bensin',    'expense',   false, '5200'],
        ['5280', 'Beban Pemasaran',                'expense',   false, '5200'],
        ['5290', 'Beban Penyusutan',               'expense',   false, '5200'],
        ['5300', 'Beban Non-Operasional',          'expense',   true,  '5000'],
        ['5310', 'Beban Bunga Bank',               'expense',   false, '5300'],
        ['5320', 'Beban Pajak',                    'expense',   false, '5300'],
        ['5330', 'Beban Lain-lain',                'expense',   false, '5300'],
    ];

    public function seedForCompany(int $companyId): int
    {
        $idMap = []; // code → id (untuk resolve parent)
        $count = 0;

        foreach (self::ACCOUNTS as [$code, $name, $type, $isHeader, $parentCode]) {
            $parentId = $parentCode ? ($idMap[$parentCode] ?? null) : null;

            $acc = Account::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $companyId, 'code' => $code],
                [
                    'name'           => $name,
                    'type'           => $type,
                    'normal_balance' => Account::defaultNormalBalance($type),
                    'parent_id'      => $parentId,
                    'is_header'      => $isHeader,
                    'is_active'      => true,
                ]
            );
            $idMap[$code] = $acc->id;
            $count++;
        }

        return $count;
    }
}
