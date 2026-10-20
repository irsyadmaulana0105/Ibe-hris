<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipment / Delivery Note untuk outbound delivery ke customer.
 *
 *   shipments         - header (DO-2026-NNNN, customer, ship date, status)
 *   shipment_items    - line items (product, serial_number, qty)
 *
 * Workflow: draft → packed → in_transit → delivered | returned | cancelled.
 *
 * Link ke Invoice opsional (kalau sales udah jadi invoice dulu, atau cash sales).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('do_number', 32)->unique();             // DO-2026-0001
            $t->foreignId('customer_id')->nullable()->index();
            $t->string('customer_name', 200);                  // snapshot for cash sale w/o customer record
            $t->text('shipping_address');
            $t->foreignId('source_warehouse_id')->nullable();
            $t->date('ship_date');
            $t->date('expected_delivery_date')->nullable();
            $t->date('actual_delivery_date')->nullable();
            $t->string('carrier', 100)->nullable();            // JNE | Sicepat | Self | Charter
            $t->string('tracking_number', 100)->nullable();
            $t->decimal('shipping_cost', 14, 2)->default(0);
            $t->string('status', 16)->default('draft');        // draft|packed|in_transit|delivered|returned|cancelled
            $t->ulid('packed_by')->nullable();
            $t->ulid('shipped_by')->nullable();
            $t->string('received_by_name', 120)->nullable();   // siapa yg terima di sisi customer
            $t->timestamp('packed_at')->nullable();
            $t->timestamp('shipped_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->foreignId('invoice_id')->nullable()->index();  // link ke fin_invoices kalau ada
            $t->text('notes')->nullable();
            $t->ulid('created_by')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['company_id', 'status', 'ship_date']);
        });

        Schema::create('shipment_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipment_id')->index();
            $t->foreignId('product_id')->nullable()->index();
            $t->string('product_serial', 64)->nullable()->index();
            $t->string('product_code', 64)->nullable();        // snapshot
            $t->string('product_name', 200)->nullable();
            $t->decimal('qty', 14, 4)->default(1);
            $t->string('unit_of_measure', 24)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
    }
};
