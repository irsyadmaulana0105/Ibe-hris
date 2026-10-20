<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend product_serial_numbers jadi proper Asset Registry:
 *   - link ke products.id (sebelumnya cuma string product_code/product_name)
 *   - spec_values JSON snapshot - value real per unit (sesuai product_spec_fields)
 *   - asset_status (in_warehouse, sold, in_service, retired, lost)
 *   - asset_location - current physical location
 *   - purchase/sales financial data
 *   - link ke fin_customer_id (kalau sudah sold/leased)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_serial_numbers', function (Blueprint $t) {
            $t->unsignedBigInteger('product_id')->nullable()->after('company_id');
            $t->json('spec_values')->nullable()->after('product_variant');
            // Asset Registry fields
            $t->enum('asset_status', ['in_warehouse', 'sold', 'leased', 'in_service', 'retired', 'lost'])
                ->default('in_warehouse')->after('status');
            $t->string('asset_location', 200)->nullable()->after('asset_status');
            $t->decimal('purchase_value', 18, 2)->nullable()->after('asset_location');
            $t->decimal('sale_value', 18, 2)->nullable()->after('purchase_value');
            $t->date('manufactured_at')->nullable()->after('sale_value');
            // Link ke Finance Customer (kalau sudah sold/leased)
            $t->unsignedBigInteger('fin_customer_id')->nullable()->after('manufactured_at');
            // Link ke CRM Deal asal (kalau dijual via CRM)
            $t->unsignedBigInteger('crm_deal_id')->nullable()->after('fin_customer_id');

            $t->index(['company_id', 'product_id']);
            $t->index(['company_id', 'asset_status']);
            $t->index('fin_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_serial_numbers', function (Blueprint $t) {
            $t->dropColumn([
                'product_id', 'spec_values', 'asset_status', 'asset_location',
                'purchase_value', 'sale_value', 'manufactured_at',
                'fin_customer_id', 'crm_deal_id',
            ]);
        });
    }
};
