<?php

namespace App\Production\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Order workflow service.
 *
 * Status transitions:
 *   draft → approved → sent → partially_received → received → closed
 *
 * Receive partial supported - track via line.qty_received.
 * Stock IN otomatis via StockService->move(reason='purchase_receipt') saat receive.
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly BatchService $batch,
    ) {}

    public function generatePoNumber(int $companyId): string
    {
        $year = date('Y');

        return DB::transaction(function () use ($companyId, $year) {
            $last = PurchaseOrder::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('po_number', 'like', "PO-{$year}-%")
                ->orderByDesc('po_number')
                ->lockForUpdate()
                ->value('po_number');
            $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

            return "PO-{$year}-".str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }

    /** Bulk replace lines + recompute total */
    public function replaceLines(PurchaseOrder $po, array $lines): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $lines) {
            if ($po->status !== 'draft') {
                throw new \RuntimeException('Hanya PO status draft yang bisa di-edit lines.');
            }

            PurchaseOrderLine::where('purchase_order_id', $po->id)->delete();

            $subtotal = 0;
            foreach ($lines as $i => $line) {
                $qty = (float) $line['qty_ordered'];
                $cost = (float) $line['unit_cost'];
                $sub = $qty * $cost;
                $subtotal += $sub;
                PurchaseOrderLine::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $line['product_id'],
                    'qty_ordered' => $qty,
                    'qty_received' => 0,
                    'unit_of_measure' => $line['unit_of_measure'] ?? null,
                    'unit_cost' => $cost,
                    'subtotal' => $sub,
                    'notes' => $line['notes'] ?? null,
                    'sort_order' => $i * 10,
                ]);
            }

            $taxAmount = $subtotal * ((float) $po->tax_rate / 100);
            $po->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $subtotal + $taxAmount,
            ]);

            return $po->fresh('lines');
        });
    }

    public function approve(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw new \RuntimeException("Cannot approve: PO status is {$po->status}");
        }
        if ($po->lines()->doesntExist()) {
            throw new \RuntimeException('PO tidak punya line item. Tambahkan dulu.');
        }
        $po->update([
            'status' => 'approved',
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        return $po->fresh();
    }

    public function send(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== 'approved') {
            throw new \RuntimeException("Cannot send: PO status is {$po->status}");
        }
        $po->update(['status' => 'sent', 'sent_at' => now()]);

        return $po->fresh();
    }

    /**
     * Receive goods - partial or full.
     * $receipts: [{line_id, qty}]
     *
     * Auto-create inventory movements untuk setiap line (reason=purchase_receipt).
     * Status update otomatis: partially_received atau received.
     *
     * $receipts item shape:
     *   {line_id, qty,
     *    batch_code?, expires_at?, supplier_lot?, manufactured_at?}
     *
     * Routing per-product:
     *   - product.track_batch = false  → langsung StockService::move (lama)
     *   - product.track_batch = true   → BatchService::receive (create batch + sync stock)
     */
    public function receive(PurchaseOrder $po, User $actor, array $receipts): PurchaseOrder
    {
        if (! in_array($po->status, ['sent', 'partially_received'])) {
            throw new \RuntimeException("Cannot receive: PO status is {$po->status} (must be sent/partially_received)");
        }

        return DB::transaction(function () use ($po, $actor, $receipts) {
            foreach ($receipts as $r) {
                $line = PurchaseOrderLine::query()
                    ->where('purchase_order_id', $po->id)
                    ->lockForUpdate()
                    ->findOrFail($r['line_id']);

                $qtyToReceive = (float) $r['qty'];
                if ($qtyToReceive <= 0) {
                    continue;
                }

                $remaining = (float) $line->qty_ordered - (float) $line->qty_received;
                if ($qtyToReceive > $remaining) {
                    throw new \RuntimeException(
                        "Line {$line->id}: receive {$qtyToReceive} melebihi sisa {$remaining}"
                    );
                }

                $product = Product::findOrFail($line->product_id);

                if ($product->track_batch) {
                    // Validate batch_code - wajib untuk product yg track_batch
                    if (empty($r['batch_code'])) {
                        throw new \RuntimeException(
                            "Product {$product->code} track_batch - line #{$line->id} wajib kasih batch_code"
                        );
                    }
                    $this->batch->receive(
                        $actor,
                        $line->product_id,
                        $po->warehouse_id,
                        $qtyToReceive,
                        $r['batch_code'],
                        [
                            'received_at' => now(),
                            'expires_at' => $r['expires_at'] ?? null,
                            'manufactured_at' => $r['manufactured_at'] ?? null,
                            'supplier_lot' => $r['supplier_lot'] ?? null,
                            'source_po_id' => $po->id,
                            'unit_cost' => $line->unit_cost,
                            'reference_type' => 'PurchaseOrder',
                            'reference_id' => (string) $po->id,
                        ],
                    );
                } else {
                    // Non-batch product - flow lama
                    $this->stock->move(
                        $actor,
                        $line->product_id,
                        $po->warehouse_id,
                        $qtyToReceive,
                        'purchase_receipt',
                        [
                            'unit_cost' => $line->unit_cost,
                            'reference_type' => 'PurchaseOrder',
                            'reference_id' => (string) $po->id,
                            'notes' => "PO {$po->po_number} line #{$line->id}",
                        ],
                    );
                }

                $line->update(['qty_received' => $line->qty_received + $qtyToReceive]);
            }

            // Update PO status: full receive or partial?
            $fullyReceived = $po->lines()
                ->whereRaw('qty_received < qty_ordered')
                ->doesntExist();
            $po->update([
                'status' => $fullyReceived ? 'received' : 'partially_received',
                'received_at' => $fullyReceived ? now() : $po->received_at,
            ]);

            return $po->fresh('lines');
        });
    }

    public function close(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== 'received') {
            throw new \RuntimeException("Cannot close: status is {$po->status}");
        }
        $po->update(['status' => 'closed', 'closed_at' => now()]);

        return $po->fresh();
    }

    public function cancel(PurchaseOrder $po, string $reason): PurchaseOrder
    {
        if (in_array($po->status, ['received', 'closed'])) {
            throw new \RuntimeException("Cannot cancel: PO already {$po->status}");
        }
        // TODO: kalau ada partial receipt, butuh logic reverse inventory dulu
        $po->update([
            'status' => 'cancelled',
            'notes' => trim(($po->notes ?? '')."\nCANCELLED: {$reason}"),
        ]);

        return $po->fresh();
    }

    public function matchToBill(PurchaseOrder $po, string $billId): PurchaseOrder
    {
        if ($po->status === 'cancelled') {
            throw new \RuntimeException('Cannot match cancelled PO');
        }
        $po->update(['fin_bill_id' => $billId]);

        return $po->fresh();
    }
}
