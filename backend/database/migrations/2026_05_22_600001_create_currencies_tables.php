<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-currency support untuk Finance + Purchase Orders.
 *
 * - `currencies`: master ISO 4217 code (USD, JPY, CNY, EUR, dll)
 * - `exchange_rates`: rate to base currency (IDR) per tanggal
 *
 * Strategy:
 *   - Base currency = IDR (sesuai PT IBE Reality registered)
 *   - Setiap transaksi (invoice/bill/PO) simpan amount di original currency + rate snapshot
 *   - Reporting/aggregation pakai IDR equivalent via rate saat itu
 *   - Gain/loss FX di-handle di JournalService (separate session)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $t) {
            $t->string('code', 3)->primary(); // ISO 4217: USD, JPY, IDR, CNY, EUR
            $t->string('name', 100);          // US Dollar, Japanese Yen, dll
            $t->string('symbol', 8)->nullable();
            $t->unsignedTinyInteger('decimals')->default(2);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_base')->default(false); // hanya 1 yang true per company → IDR
            $t->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('from_currency', 3);  // e.g. USD
            $t->string('to_currency', 3);    // e.g. IDR (selalu base)
            $t->decimal('rate', 18, 8);       // 1 USD = 15650.12345678 IDR
            $t->date('valid_from');           // berlaku mulai
            $t->string('source', 50)->nullable(); // 'BI', 'manual', 'XE.com', dll
            $t->char('created_by', 26)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'from_currency', 'to_currency', 'valid_from'], 'rate_unique');
            $t->index(['company_id', 'from_currency', 'valid_from']);
        });

        // Seed common currencies
        $now = now();
        DB::table('currencies')->insert([
            ['code' => 'IDR', 'name' => 'Rupiah Indonesia', 'symbol' => 'Rp', 'decimals' => 0, 'is_active' => true, 'is_base' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'is_active' => true, 'is_base' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_active' => true, 'is_base' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'decimals' => 0, 'is_active' => true, 'is_base' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'decimals' => 2, 'is_active' => true, 'is_base' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'decimals' => 2, 'is_active' => true, 'is_base' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'KRW', 'name' => 'Korean Won', 'symbol' => '₩', 'decimals' => 0, 'is_active' => true, 'is_base' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'decimals' => 2, 'is_active' => true, 'is_base' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Add rate_to_base + base_amount columns to existing tables
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->decimal('exchange_rate', 18, 8)->default(1)->after('currency');
            $t->decimal('total_in_base', 18, 2)->default(0)->after('exchange_rate'); // IDR equivalent
        });

        // Bills already have currency? Check fin_bills
        if (Schema::hasTable('fin_bills') && !Schema::hasColumn('fin_bills', 'exchange_rate')) {
            Schema::table('fin_bills', function (Blueprint $t) {
                $t->decimal('exchange_rate', 18, 8)->default(1);
                $t->decimal('total_in_base', 18, 2)->default(0);
            });
        }

        // Invoices already have currency? Check fin_invoices
        if (Schema::hasTable('fin_invoices') && !Schema::hasColumn('fin_invoices', 'exchange_rate')) {
            Schema::table('fin_invoices', function (Blueprint $t) {
                $t->decimal('exchange_rate', 18, 8)->default(1);
                $t->decimal('total_in_base', 18, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropColumn(['exchange_rate', 'total_in_base']);
        });
        if (Schema::hasColumn('fin_bills', 'exchange_rate')) {
            Schema::table('fin_bills', function (Blueprint $t) {
                $t->dropColumn(['exchange_rate', 'total_in_base']);
            });
        }
        if (Schema::hasColumn('fin_invoices', 'exchange_rate')) {
            Schema::table('fin_invoices', function (Blueprint $t) {
                $t->dropColumn(['exchange_rate', 'total_in_base']);
            });
        }
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
