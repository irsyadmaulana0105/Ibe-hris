<?php

namespace App\Services;

use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Quotation / RAB service: number generation, totals recompute,
 * status transitions (draft → sent → accepted/rejected), auto-convert to invoice.
 */
class QuotationService
{
    public function generateQuoteNumber(int $companyId): string
    {
        $ym = date('Y/m');
        return DB::transaction(function () use ($companyId, $ym) {
            $last = Quotation::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('quote_number', 'like', "RAB-{$ym}/%")
                ->orderByDesc('quote_number')
                ->lockForUpdate()
                ->value('quote_number');
            $seq = $last ? ((int) substr($last, -4)) + 1 : 1;
            return "RAB-{$ym}/" . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }

    public function create(User $actor, array $data): Quotation
    {
        return DB::transaction(function () use ($actor, $data) {
            $q = Quotation::create([
                'company_id'         => $actor->company_id,
                'quote_number'       => $this->generateQuoteNumber($actor->company_id),
                'title'              => $data['title'],
                'project_name'       => $data['project_name'] ?? null,
                'customer_id'        => $data['customer_id'] ?? null,
                'customer_name'      => $data['customer_name'],
                'customer_address'   => $data['customer_address'] ?? null,
                'customer_pic_name'  => $data['customer_pic_name'] ?? null,
                'customer_pic_title' => $data['customer_pic_title'] ?? null,
                'quote_date'         => $data['quote_date'] ?? now()->toDateString(),
                'valid_until'        => $data['valid_until'] ?? now()->addDays(30)->toDateString(),
                'crm_deal_id'        => $data['crm_deal_id'] ?? null,
                'tax_rate'           => $data['tax_rate'] ?? 11,
                'discount'           => $data['discount'] ?? 0,
                'discount_pct'       => $data['discount_pct'] ?? 0,
                'payment_terms'      => $data['payment_terms'] ?? '30% Down Payment, 70% pelunasan setelah barang diterima.',
                'delivery_terms'     => $data['delivery_terms'] ?? 'Pengiriman maksimal 14 hari kerja setelah DP diterima.',
                'warranty_terms'     => $data['warranty_terms'] ?? 'Garansi 12 bulan untuk komponen mekanik dan elektrik.',
                'preamble'           => $data['preamble'] ?? "Dengan hormat,\n\nMerujuk permintaan penawaran Bapak/Ibu, dengan ini kami sampaikan Rencana Anggaran Biaya (RAB) sebagai berikut:",
                'notes'              => $data['notes'] ?? null,
                'status'             => 'draft',
                'prepared_by'        => $actor->id,
            ]);

            foreach ($data['items'] ?? [] as $idx => $item) {
                QuotationItem::create([
                    'quotation_id'  => $q->id,
                    'section_label' => $item['section_label'] ?? null,
                    'sort_order'    => $item['sort_order'] ?? $idx,
                    'product_id'    => $item['product_id'] ?? null,
                    'description'   => $item['description'],
                    'qty'           => (float) ($item['qty'] ?? 1),
                    'unit'          => $item['unit'] ?? 'unit',
                    'unit_price'    => (float) ($item['unit_price'] ?? 0),
                    'subtotal'      => (float) ($item['qty'] ?? 1) * (float) ($item['unit_price'] ?? 0),
                    'notes'         => $item['notes'] ?? null,
                ]);
            }

            $this->recomputeTotals($q->fresh('items'));
            return $q->fresh('items');
        });
    }

    public function update(Quotation $q, array $data): Quotation
    {
        return DB::transaction(function () use ($q, $data) {
            $headerFields = [
                'title', 'project_name', 'customer_id', 'customer_name', 'customer_address',
                'customer_pic_name', 'customer_pic_title', 'quote_date', 'valid_until',
                'crm_deal_id', 'tax_rate', 'discount', 'discount_pct',
                'payment_terms', 'delivery_terms', 'warranty_terms', 'notes', 'preamble',
            ];
            $q->update(array_intersect_key($data, array_flip($headerFields)));

            if (isset($data['items'])) {
                $q->items()->delete();
                foreach ($data['items'] as $idx => $item) {
                    QuotationItem::create([
                        'quotation_id'  => $q->id,
                        'section_label' => $item['section_label'] ?? null,
                        'sort_order'    => $item['sort_order'] ?? $idx,
                        'product_id'    => $item['product_id'] ?? null,
                        'description'   => $item['description'],
                        'qty'           => (float) ($item['qty'] ?? 1),
                        'unit'          => $item['unit'] ?? 'unit',
                        'unit_price'    => (float) ($item['unit_price'] ?? 0),
                        'subtotal'      => (float) ($item['qty'] ?? 1) * (float) ($item['unit_price'] ?? 0),
                        'notes'         => $item['notes'] ?? null,
                    ]);
                }
            }

            $this->recomputeTotals($q->fresh('items'));
            return $q->fresh('items');
        });
    }

    public function recomputeTotals(Quotation $q): void
    {
        $subtotal = (float) $q->items->sum('subtotal');
        $discountPct = (float) $q->discount_pct;
        $discountAmount = (float) $q->discount;

        // Compute discount: pct takes precedence if > 0
        if ($discountPct > 0) {
            $discountAmount = round($subtotal * $discountPct / 100, 2);
        }
        $afterDiscount = $subtotal - $discountAmount;
        $taxAmount = round($afterDiscount * ((float) $q->tax_rate) / 100, 2);
        $total = $afterDiscount + $taxAmount;

        $q->update([
            'subtotal'       => round($subtotal, 2),
            'discount'       => round($discountAmount, 2),
            'after_discount' => round($afterDiscount, 2),
            'tax_amount'     => $taxAmount,
            'total'          => round($total, 2),
        ]);
    }

    public function send(Quotation $q): Quotation
    {
        if ($q->status !== 'draft') {
            throw new \RuntimeException("Quote {$q->quote_number} sudah pernah dikirim atau di-finalisasi (status: {$q->status}).");
        }
        $update = ['status' => 'sent', 'sent_at' => now()];
        if (!$q->public_token) {
            $update['public_token'] = Str::random(48);
        }
        $q->update($update);
        return $q->fresh();
    }

    public function ensurePublicToken(Quotation $q): string
    {
        if (!$q->public_token) {
            $q->update(['public_token' => Str::random(48)]);
            $q->refresh();
        }
        return $q->public_token;
    }

    public function accept(Quotation $q): Quotation
    {
        if (!in_array($q->status, ['draft', 'sent'], true)) {
            throw new \RuntimeException("Quote {$q->quote_number} tidak dapat di-accept dari status {$q->status}.");
        }
        $q->update(['status' => 'accepted', 'accepted_at' => now()]);
        return $q;
    }

    public function reject(Quotation $q, ?string $reason = null): Quotation
    {
        if (!in_array($q->status, ['draft', 'sent'], true)) {
            throw new \RuntimeException("Quote {$q->quote_number} tidak dapat di-reject dari status {$q->status}.");
        }
        $q->update(['status' => 'rejected', 'rejected_at' => now(), 'rejection_reason' => $reason]);
        return $q;
    }

    /**
     * Group items per section for rendering. Returns array:
     *   [
     *     ['label' => 'I. Pekerjaan Persiapan', 'items' => [...], 'subtotal' => 1234567],
     *     ['label' => 'II. ...', ...],
     *   ]
     * Items without section_label go into '_ungrouped'.
     */
    public function groupedSections(Quotation $q): array
    {
        $groups = [];
        foreach ($q->items as $item) {
            $key = $item->section_label ?: '_ungrouped';
            if (!isset($groups[$key])) {
                $groups[$key] = ['label' => $item->section_label, 'items' => [], 'subtotal' => 0];
            }
            $groups[$key]['items'][] = $item;
            $groups[$key]['subtotal'] += (float) $item->subtotal;
        }
        return array_values($groups);
    }
}
