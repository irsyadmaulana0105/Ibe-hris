<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Order workflow.
 *
 * Status flow:
 *   draft → approved → sent → partially_received → received → closed
 *   (any non-closed) → cancelled
 *
 * Receive partial supported - line.qty_received bisa bertambah seiring waktu.
 * Stock IN otomatis via InventoryMovement saat receive (reason=purchase_receipt).
 * Match ke fin_bill saat invoice dari supplier datang (3-way match: PO ↔ GR ↔ Bill).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('po_number', 32);             // PO-2026-0001
            $t->unsignedBigInteger('supplier_id');   // FK to fin_suppliers
            $t->unsignedBigInteger('warehouse_id');  // destination
            $t->date('date');
            $t->date('expected_delivery_date')->nullable();
            $t->enum('status', [
                'draft', 'approved', 'sent', 'partially_received', 'received', 'closed', 'cancelled',
            ])->default('draft')->index();
            $t->decimal('subtotal', 18, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(11);
            $t->decimal('tax_amount', 18, 2)->default(0);
            $t->decimal('total', 18, 2)->default(0);
            $t->string('currency', 3)->default('IDR');
            $t->text('terms')->nullable();
            $t->text('notes')->nullable();
            $t->ulid('fin_bill_id')->nullable(); // matched bill kalau sudah invoice datang
            $t->char('created_by', 26);
            $t->char('approved_by', 26)->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'po_number']);
            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'supplier_id']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('purchase_order_id');
            $t->unsignedBigInteger('product_id');
            $t->decimal('qty_ordered', 18, 2);
            $t->decimal('qty_received', 18, 2)->default(0);
            $t->string('unit_of_measure', 20)->nullable();
            $t->decimal('unit_cost', 18, 2);
            $t->decimal('subtotal', 18, 2);    // qty × unit_cost
            $t->text('notes')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['purchase_order_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
