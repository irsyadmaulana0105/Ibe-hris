<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommercialWorkflowService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly WebhookService $webhooks,
    ) {}

    public function nextNumber(string $table, string $column, string $prefix, int $companyId): string
    {
        $year = now()->format('Y');
        $last = DB::table($table)->where('company_id', $companyId)
            ->where($column, 'like', "{$prefix}-{$year}-%")
            ->orderByDesc($column)->lockForUpdate()->value($column);
        $segments = $last ? explode('-', (string) $last) : [];
        $sequence = $last ? ((int) end($segments)) + 1 : 1;

        return sprintf('%s-%s-%05d', $prefix, $year, $sequence);
    }

    public function createSalesOrder(User $actor, array $data): object
    {
        return DB::transaction(function () use ($actor, $data) {
            $companyId = (int) $actor->company_id;
            $subtotal = collect($data['lines'])->sum(fn ($line) => (float) $line['quantity'] * (float) $line['unit_price'] * (1 - ((float) ($line['discount_percent'] ?? 0) / 100)));
            $discount = (float) ($data['discount_amount'] ?? 0);
            $tax = max(0, $subtotal - $discount) * ((float) ($data['tax_rate'] ?? 11) / 100);
            $total = max(0, $subtotal - $discount) + $tax;
            $commissionRate = (float) ($data['commission_rate'] ?? 0);
            $id = DB::table('sales_orders')->insertGetId([
                'company_id' => $companyId, 'order_number' => $this->nextNumber('sales_orders', 'order_number', 'SO', $companyId),
                'customer_id' => $data['customer_id'] ?? null, 'customer_name' => $data['customer_name'],
                'quotation_id' => $data['quotation_id'] ?? null, 'order_date' => $data['order_date'],
                'requested_delivery_date' => $data['requested_delivery_date'] ?? null, 'status' => 'draft',
                'currency' => $data['currency'] ?? 'IDR', 'subtotal' => $subtotal, 'discount_amount' => $discount,
                'tax_amount' => $tax, 'total' => $total, 'paid_amount' => 0, 'payment_status' => 'unpaid',
                'sales_person_id' => $data['sales_person_id'] ?? $actor->id, 'commission_rate' => $commissionRate,
                'commission_amount' => $total * $commissionRate / 100, 'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($data['lines'] as $line) {
                $lineTotal = (float) $line['quantity'] * (float) $line['unit_price'] * (1 - ((float) ($line['discount_percent'] ?? 0) / 100));
                DB::table('sales_order_lines')->insert([
                    'sales_order_id' => $id, 'product_id' => $line['product_id'] ?? null, 'item_code' => $line['item_code'] ?? null,
                    'description' => $line['description'], 'quantity' => $line['quantity'], 'unit_of_measure' => $line['unit_of_measure'] ?? null,
                    'unit_price' => $line['unit_price'], 'discount_percent' => $line['discount_percent'] ?? 0,
                    'line_total' => $lineTotal, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $order = DB::table('sales_orders')->where('id', $id)->first();
            $this->audit->log('sales.order.created', $actor, 'sales_order', $id, ['order_number' => $order->order_number]);
            $this->webhooks->publish($companyId, 'sales_order.created', ['id' => $id, 'order_number' => $order->order_number, 'total' => (float) $order->total], $this->correlationId());

            return $order;
        });
    }

    public function transitionSalesOrder(User $actor, int $id, string $action): object
    {
        $order = DB::table('sales_orders')->where('company_id', $actor->company_id)->where('id', $id)->first();
        abort_unless($order, 404);
        $transitions = ['draft' => ['confirm' => 'confirmed', 'cancel' => 'cancelled'], 'confirmed' => ['fulfill' => 'fulfilled', 'cancel' => 'cancelled']];
        $next = $transitions[$order->status][$action] ?? null;
        if (! $next) {
            throw ValidationException::withMessages(['status' => "Aksi {$action} tidak valid dari status {$order->status}."]);
        }
        DB::table('sales_orders')->where('id', $id)->update(['status' => $next, 'updated_at' => now()]);
        $this->audit->log('sales.order.status_changed', $actor, 'sales_order', $id, ['from' => $order->status, 'to' => $next, 'action' => $action]);

        return DB::table('sales_orders')->where('id', $id)->first();
    }

    public function allocatePayment(User $actor, int $id, array $data): object
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $order = DB::table('sales_orders')->where('company_id', $actor->company_id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            $amount = (float) $data['amount'];
            if ($amount <= 0 || (float) $order->paid_amount + $amount > (float) $order->total) {
                throw ValidationException::withMessages(['amount' => 'Alokasi pembayaran harus positif dan tidak boleh melebihi sisa tagihan.']);
            }
            DB::table('sales_payment_allocations')->insert([
                'company_id' => $actor->company_id, 'sales_order_id' => $id, 'reference' => $data['reference'] ?? null,
                'payment_date' => $data['payment_date'], 'amount' => $amount, 'method' => $data['method'] ?? null,
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $paid = (float) $order->paid_amount + $amount;
            DB::table('sales_orders')->where('id', $id)->update([
                'paid_amount' => $paid, 'payment_status' => $paid >= (float) $order->total ? 'paid' : 'partial', 'updated_at' => now(),
            ]);
            $this->audit->log('sales.payment.allocated', $actor, 'sales_order', $id, ['amount' => $amount, 'reference' => $data['reference'] ?? null]);

            if ($paid >= (float) $order->total) {
                $this->webhooks->publish((int) $actor->company_id, 'payment.completed', ['sales_order_id' => $id, 'amount' => $paid, 'reference' => $data['reference'] ?? null], $this->correlationId());
            }

            return DB::table('sales_orders')->where('id', $id)->first();
        });
    }

    public function createPurchaseRequest(User $actor, array $data): object
    {
        return DB::transaction(function () use ($actor, $data) {
            $companyId = (int) $actor->company_id;
            $id = DB::table('purchase_requests')->insertGetId([
                'company_id' => $companyId, 'request_number' => $this->nextNumber('purchase_requests', 'request_number', 'PR', $companyId),
                'department' => $data['department'] ?? null, 'request_date' => $data['request_date'], 'required_date' => $data['required_date'] ?? null,
                'status' => 'draft', 'priority' => $data['priority'] ?? 'normal', 'items' => json_encode($data['items']),
                'business_reason' => $data['business_reason'] ?? null, 'requested_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $row = DB::table('purchase_requests')->where('id', $id)->first();
            $this->audit->log('procurement.request.created', $actor, 'purchase_request', $id, ['request_number' => $row->request_number]);
            $this->webhooks->publish($companyId, 'purchase_request.created', ['id' => $id, 'request_number' => $row->request_number, 'department' => $row->department], $this->correlationId());

            return $row;
        });
    }

    public function transitionPurchaseRequest(User $actor, int $id, string $action): object
    {
        $request = DB::table('purchase_requests')->where('company_id', $actor->company_id)->where('id', $id)->first();
        abort_unless($request, 404);
        $transitions = ['draft' => ['submit' => 'submitted', 'cancel' => 'cancelled'], 'submitted' => ['approve' => 'approved', 'reject' => 'rejected']];
        $next = $transitions[$request->status][$action] ?? null;
        if (! $next) {
            throw ValidationException::withMessages(['status' => "Aksi {$action} tidak valid dari status {$request->status}."]);
        }
        $extra = in_array($next, ['approved', 'rejected'], true) ? ['approved_by' => $actor->id, 'approved_at' => now()] : [];
        DB::table('purchase_requests')->where('id', $id)->update([...$extra, 'status' => $next, 'updated_at' => now()]);
        $this->audit->log('procurement.request.status_changed', $actor, 'purchase_request', $id, ['from' => $request->status, 'to' => $next, 'action' => $action]);

        if ($next === 'approved') {
            $this->webhooks->publish((int) $actor->company_id, 'purchase_request.approved', ['id' => $id, 'request_number' => $request->request_number, 'approved_by' => $actor->id], $this->correlationId());
        }

        return DB::table('purchase_requests')->where('id', $id)->first();
    }

    public function createRfq(User $actor, array $data): object
    {
        return DB::transaction(function () use ($actor, $data) {
            $companyId = (int) $actor->company_id;
            $id = DB::table('procurement_rfqs')->insertGetId([
                'company_id' => $companyId, 'rfq_number' => $this->nextNumber('procurement_rfqs', 'rfq_number', 'RFQ', $companyId),
                'purchase_request_id' => $data['purchase_request_id'] ?? null, 'issue_date' => $data['issue_date'],
                'closing_date' => $data['closing_date'] ?? null, 'status' => 'draft', 'items' => json_encode($data['items']),
                'vendor_quotes' => isset($data['vendor_quotes']) ? json_encode($data['vendor_quotes']) : null,
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $row = DB::table('procurement_rfqs')->where('id', $id)->first();
            $this->audit->log('procurement.rfq.created', $actor, 'procurement_rfq', $id, ['rfq_number' => $row->rfq_number]);

            return $row;
        });
    }

    public function selectRfqVendor(User $actor, int $id, array $data): object
    {
        $rfq = DB::table('procurement_rfqs')->where('company_id', $actor->company_id)->where('id', $id)->first();
        abort_unless($rfq, 404);
        $supplierExists = DB::table('fin_suppliers')->where('company_id', $actor->company_id)->where('id', $data['supplier_id'])->exists();
        abort_unless($supplierExists, 404);
        DB::table('procurement_rfqs')->where('id', $id)->update([
            'status' => 'awarded', 'selected_supplier_id' => $data['supplier_id'],
            'selection_notes' => $data['selection_notes'] ?? null, 'updated_at' => now(),
        ]);
        $this->audit->log('procurement.rfq.vendor_selected', $actor, 'procurement_rfq', $id, ['supplier_id' => $data['supplier_id'], 'selection_notes' => $data['selection_notes'] ?? null]);

        return DB::table('procurement_rfqs')->where('id', $id)->first();
    }

    public function createFleet(User $actor, array $data): object
    {
        $id = DB::table('delivery_fleets')->insertGetId([...$data, 'company_id' => $actor->company_id, 'created_at' => now(), 'updated_at' => now()]);

        $row = DB::table('delivery_fleets')->where('id', $id)->first();
        $this->audit->log('distribution.fleet.created', $actor, 'delivery_fleet', $id, ['vehicle_number' => $row->vehicle_number]);

        return $row;
    }

    public function createSalesReturn(User $actor, array $data): object
    {
        return DB::transaction(function () use ($actor, $data) {
            $order = DB::table('sales_orders')->where('company_id', $actor->company_id)->where('id', $data['sales_order_id'])->first();
            abort_unless($order, 404);
            $total = collect($data['items'])->sum(fn ($item) => (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0));
            if ($total > (float) $order->total) {
                throw ValidationException::withMessages(['items' => 'Nilai retur tidak boleh melebihi nilai Sales Order.']);
            }
            $id = DB::table('sales_returns')->insertGetId([
                'company_id' => $actor->company_id,
                'return_number' => $this->nextNumber('sales_returns', 'return_number', 'SR', (int) $actor->company_id),
                'sales_order_id' => $order->id,
                'return_date' => $data['return_date'],
                'status' => 'draft',
                'reason' => $data['reason'],
                'items' => json_encode($data['items']),
                'total' => $total,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table('sales_returns')->where('id', $id)->first();
            $this->audit->log('sales.return.created', $actor, 'sales_return', $id, ['return_number' => $row->return_number, 'sales_order_id' => $order->id]);

            return $row;
        });
    }

    public function transitionSalesReturn(User $actor, int $id, string $action): object
    {
        return $this->transitionReturn($actor, 'sales_returns', 'sales_return', $id, $action, [
            'draft' => ['submit' => 'submitted', 'cancel' => 'cancelled'],
            'submitted' => ['approve' => 'approved', 'reject' => 'rejected'],
            'approved' => ['complete' => 'completed'],
        ]);
    }

    public function createDeliveryReturn(User $actor, array $data): object
    {
        return DB::transaction(function () use ($actor, $data) {
            $shipment = DB::table('shipments')->where('company_id', $actor->company_id)->where('id', $data['shipment_id'])->first();
            abort_unless($shipment, 404);
            $id = DB::table('delivery_returns')->insertGetId([
                'company_id' => $actor->company_id,
                'return_number' => $this->nextNumber('delivery_returns', 'return_number', 'DR', (int) $actor->company_id),
                'shipment_id' => $shipment->id,
                'return_date' => $data['return_date'],
                'status' => 'reported',
                'reason' => $data['reason'],
                'items' => isset($data['items']) ? json_encode($data['items']) : null,
                'evidence_path' => $data['evidence_path'] ?? null,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table('delivery_returns')->where('id', $id)->first();
            $this->audit->log('distribution.return.created', $actor, 'delivery_return', $id, ['return_number' => $row->return_number, 'shipment_id' => $shipment->id]);

            return $row;
        });
    }

    public function transitionDeliveryReturn(User $actor, int $id, string $action): object
    {
        return $this->transitionReturn($actor, 'delivery_returns', 'delivery_return', $id, $action, [
            'reported' => ['review' => 'under_review', 'cancel' => 'cancelled'],
            'under_review' => ['approve' => 'approved', 'reject' => 'rejected'],
            'approved' => ['complete' => 'completed'],
        ]);
    }

    private function transitionReturn(User $actor, string $table, string $entity, int $id, string $action, array $transitions): object
    {
        $row = DB::table($table)->where('company_id', $actor->company_id)->where('id', $id)->first();
        abort_unless($row, 404);
        $next = $transitions[$row->status][$action] ?? null;
        if (! $next) {
            throw ValidationException::withMessages(['status' => "Aksi {$action} tidak valid dari status {$row->status}."]);
        }
        $review = in_array($next, ['approved', 'rejected'], true) ? ['reviewed_by' => $actor->id, 'reviewed_at' => now()] : [];
        DB::table($table)->where('id', $id)->update([...$review, 'status' => $next, 'updated_at' => now()]);
        $this->audit->log("{$entity}.status_changed", $actor, $entity, $id, ['from' => $row->status, 'to' => $next, 'action' => $action]);

        return DB::table($table)->where('id', $id)->first();
    }

    public function convertQuotation(User $actor, int $quotationId): object
    {
        return DB::transaction(function () use ($actor, $quotationId) {
            $quote = DB::table('quotations')->where('company_id', $actor->company_id)->where('id', $quotationId)->lockForUpdate()->first();
            abort_unless($quote, 404);
            $existing = DB::table('sales_orders')->where('company_id', $actor->company_id)->where('quotation_id', $quotationId)->first();
            if ($existing) {
                return $existing;
            }
            if (! in_array($quote->status, ['accepted', 'sent'], true)) {
                throw ValidationException::withMessages(['quotation_id' => 'Quotation harus berstatus sent atau accepted sebelum dikonversi.']);
            }
            $lines = DB::table('quotation_items')->where('quotation_id', $quotationId)->orderBy('sort_order')->get()->map(fn ($line) => [
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => $line->qty,
                'unit_of_measure' => $line->unit,
                'unit_price' => $line->unit_price,
                'discount_percent' => 0,
            ])->all();
            if ($lines === []) {
                throw ValidationException::withMessages(['quotation_id' => 'Quotation tidak memiliki item.']);
            }

            return $this->createSalesOrder($actor, [
                'customer_id' => $quote->customer_id,
                'customer_name' => $quote->customer_name,
                'quotation_id' => $quote->id,
                'order_date' => now()->toDateString(),
                'requested_delivery_date' => null,
                'discount_amount' => $quote->discount,
                'tax_rate' => $quote->tax_rate,
                'notes' => "Konversi dari {$quote->quote_number}",
                'lines' => $lines,
            ]);
        });
    }

    public function convertRfqToPurchaseOrder(User $actor, int $rfqId, array $data): object
    {
        return DB::transaction(function () use ($actor, $rfqId, $data) {
            $rfq = DB::table('procurement_rfqs')->where('company_id', $actor->company_id)->where('id', $rfqId)->lockForUpdate()->first();
            abort_unless($rfq, 404);
            if ($rfq->purchase_order_id) {
                return DB::table('purchase_orders')->where('company_id', $actor->company_id)->where('id', $rfq->purchase_order_id)->first();
            }
            if ($rfq->status !== 'awarded' || ! $rfq->selected_supplier_id) {
                throw ValidationException::withMessages(['rfq_id' => 'Pilih vendor pemenang sebelum membuat Purchase Order.']);
            }
            abort_unless(DB::table('fin_suppliers')->where('company_id', $actor->company_id)->where('id', $rfq->selected_supplier_id)->exists(), 404);
            abort_unless(DB::table('inventory_warehouses')->where('company_id', $actor->company_id)->where('id', $data['warehouse_id'])->exists(), 404);
            $productIds = collect($data['lines'])->pluck('product_id')->unique()->values();
            $tenantProductCount = DB::table('products')->where('company_id', $actor->company_id)->whereIn('id', $productIds)->count();
            if ($tenantProductCount !== $productIds->count()) {
                throw ValidationException::withMessages(['lines' => 'Semua produk Purchase Order harus berasal dari perusahaan aktif.']);
            }
            $subtotal = collect($data['lines'])->sum(fn ($line) => (float) $line['quantity'] * (float) $line['unit_cost']);
            $taxRate = (float) ($data['tax_rate'] ?? 11);
            $tax = $subtotal * $taxRate / 100;
            $poId = DB::table('purchase_orders')->insertGetId([
                'company_id' => $actor->company_id,
                'po_number' => $this->nextNumber('purchase_orders', 'po_number', 'PO', (int) $actor->company_id),
                'supplier_id' => $rfq->selected_supplier_id,
                'warehouse_id' => $data['warehouse_id'],
                'date' => $data['date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'status' => 'draft',
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $tax,
                'total' => $subtotal + $tax,
                'currency' => $data['currency'] ?? 'IDR',
                'notes' => 'Konversi dari '.$rfq->rfq_number,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($data['lines'] as $index => $line) {
                DB::table('purchase_order_lines')->insert([
                    'purchase_order_id' => $poId,
                    'product_id' => $line['product_id'],
                    'qty_ordered' => $line['quantity'],
                    'qty_received' => 0,
                    'unit_of_measure' => $line['unit_of_measure'] ?? null,
                    'unit_cost' => $line['unit_cost'],
                    'subtotal' => (float) $line['quantity'] * (float) $line['unit_cost'],
                    'notes' => $line['notes'] ?? null,
                    'sort_order' => $index * 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('procurement_rfqs')->where('id', $rfqId)->update(['purchase_order_id' => $poId, 'status' => 'converted', 'updated_at' => now()]);
            $po = DB::table('purchase_orders')->where('id', $poId)->first();
            $this->audit->log('procurement.rfq.converted_to_po', $actor, 'procurement_rfq', $rfqId, ['purchase_order_id' => $poId, 'po_number' => $po->po_number]);

            return $po;
        });
    }

    private function correlationId(): ?string
    {
        return app()->bound('correlation_id') ? (string) app('correlation_id') : null;
    }
}
