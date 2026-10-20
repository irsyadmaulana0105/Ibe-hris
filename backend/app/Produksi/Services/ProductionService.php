<?php

namespace App\Produksi\Services;

use App\Models\Complaint;
use App\Models\ProductSerialNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Production team utilities: generate serial number (format
 * IBE-{PRODUCT_CODE}-{YYYYMM}-{4-digit-seq}) dan ticket complaint
 * (CMP-{YYYY}-{4-digit-seq}). Sequence di-scope per (company, year[-month]).
 */
class ProductionService
{
    /** Generate format: IBE-RBT-202605-0001 (per company-product-bulan) */
    public function generateSerialNumber(int $companyId, string $productCode): string
    {
        $productCode = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $productCode));
        $yearMonth = now()->format('Ym');
        $prefix = "IBE-{$productCode}-{$yearMonth}-";

        return DB::transaction(function () use ($companyId, $productCode, $prefix) {
            $last = ProductSerialNumber::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('product_code', $productCode)
                ->where('serial_number', 'like', $prefix.'%')
                ->orderByDesc('serial_number')
                ->lockForUpdate()
                ->first(['serial_number']);

            $nextSeq = 1;
            if ($last) {
                $suffix = substr($last->serial_number, strlen($prefix));
                $nextSeq = ((int) $suffix) + 1;
            }

            return $prefix.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
        });
    }

    /** Generate ticket number: CMP-2026-0001 (per company-year) */
    public function generateTicketNumber(int $companyId): string
    {
        $year = now()->year;
        $prefix = "CMP-{$year}-";

        return DB::transaction(function () use ($companyId, $prefix) {
            $last = Complaint::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('ticket_number', 'like', $prefix.'%')
                ->orderByDesc('ticket_number')
                ->lockForUpdate()
                ->first(['ticket_number']);

            $nextSeq = 1;
            if ($last) {
                $suffix = substr($last->ticket_number, strlen($prefix));
                $nextSeq = ((int) $suffix) + 1;
            }

            return $prefix.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Resolve product info dari serial number (kalau ada). Dipakai saat customer
     * submit complaint dengan SN → backend auto-fill product_name.
     */
    public function lookupSerial(string $serialNumber): ?ProductSerialNumber
    {
        return ProductSerialNumber::query()->withoutGlobalScopes()
            ->where('serial_number', strtoupper(trim($serialNumber)))
            ->first();
    }

    public function recordComplaint(array $data, ?int $companyId = null): Complaint
    {
        $companyId = $companyId ?? (int) ($data['company_id'] ?? 1);
        $ticket = $this->generateTicketNumber($companyId);

        // Lookup product info kalau ada SN
        if (! empty($data['product_serial'])) {
            $sn = $this->lookupSerial($data['product_serial']);
            if ($sn && empty($data['product_name'])) {
                $data['product_name'] = trim(($sn->product_name).($sn->product_variant ? " ({$sn->product_variant})" : ''));
            }
        }

        $complaint = Complaint::create(array_merge([
            'id' => (string) Str::ulid(),
            'company_id' => $companyId,
            'ticket_number' => $ticket,
            'source' => 'manual',
            'priority' => 'normal',
            'status' => 'new',
            'category' => $data['category'] ?? 'other',
        ], $data));

        // Apply SLA defaults from priority (only if column exists - graceful for fresh deploys)
        if (\Schema::hasColumn('complaints', 'target_response_at')) {
            app(\App\Services\ServiceTicketService::class)->applySlaDefaults($complaint);
            $complaint->save();
        }

        return $complaint;
    }
}
