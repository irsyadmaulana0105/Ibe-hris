<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('order_number', 40);
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('customer_name', 200);
            $table->unsignedBigInteger('quotation_id')->nullable()->index();
            $table->date('order_date');
            $table->date('requested_delivery_date')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->string('currency', 3)->default('IDR');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->string('payment_status', 20)->default('unpaid')->index();
            $table->char('sales_person_id', 26)->nullable()->index();
            $table->decimal('commission_rate', 7, 4)->default(0);
            $table->decimal('commission_amount', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'order_number']);
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id')->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('item_code', 64)->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 18, 4);
            $table->string('unit_of_measure', 24)->nullable();
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_percent', 7, 4)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();
        });

        Schema::create('sales_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('sales_order_id')->index();
            $table->string('reference', 100)->nullable();
            $table->date('payment_date');
            $table->decimal('amount', 18, 2);
            $table->string('method', 40)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('return_number', 40);
            $table->unsignedBigInteger('sales_order_id')->index();
            $table->date('return_date');
            $table->string('status', 24)->default('draft')->index();
            $table->text('reason');
            $table->json('items');
            $table->decimal('total', 18, 2)->default(0);
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'return_number']);
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('request_number', 40);
            $table->string('department', 100)->nullable();
            $table->date('request_date');
            $table->date('required_date')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->string('priority', 16)->default('normal');
            $table->json('items');
            $table->text('business_reason')->nullable();
            $table->char('requested_by', 26);
            $table->char('approved_by', 26)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'request_number']);
        });

        Schema::create('procurement_rfqs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('rfq_number', 40);
            $table->unsignedBigInteger('purchase_request_id')->nullable()->index();
            $table->date('issue_date');
            $table->date('closing_date')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->json('items');
            $table->json('vendor_quotes')->nullable();
            $table->unsignedBigInteger('selected_supplier_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_order_id')->nullable()->index();
            $table->text('selection_notes')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'rfq_number']);
        });

        Schema::create('delivery_fleets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('vehicle_number', 40);
            $table->string('vehicle_type', 80)->nullable();
            $table->string('driver_name', 120)->nullable();
            $table->string('driver_phone', 40)->nullable();
            $table->string('status', 20)->default('available')->index();
            $table->decimal('capacity', 18, 2)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'vehicle_number']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_order_id')->nullable()->index();
            $table->unsignedBigInteger('fleet_id')->nullable()->index();
            $table->text('delivery_route')->nullable();
            $table->string('pod_file_path')->nullable();
            $table->text('pod_notes')->nullable();
            $table->decimal('pod_latitude', 10, 7)->nullable();
            $table->decimal('pod_longitude', 10, 7)->nullable();
        });

        Schema::create('delivery_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('return_number', 40);
            $table->unsignedBigInteger('shipment_id')->index();
            $table->date('return_date');
            $table->string('status', 24)->default('reported')->index();
            $table->text('reason');
            $table->json('items')->nullable();
            $table->string('evidence_path')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'return_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_returns');
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['sales_order_id', 'fleet_id', 'delivery_route', 'pod_file_path', 'pod_notes', 'pod_latitude', 'pod_longitude']);
        });
        Schema::dropIfExists('delivery_fleets');
        Schema::dropIfExists('procurement_rfqs');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('sales_payment_allocations');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
