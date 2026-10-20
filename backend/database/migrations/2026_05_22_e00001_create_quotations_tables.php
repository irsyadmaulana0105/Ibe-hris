<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotation / RAB (Rencana Anggaran Biaya) + Surat Penawaran.
 *
 * Master tabel:
 *   quotations       - header (RAB number, customer, totals, status workflow)
 *   quotation_items  - line items dengan section grouping (I, II, III, dst)
 *
 * Workflow: draft → sent → accepted | rejected | expired
 * On accepted: optional auto-convert ke fin_invoice (sales pipeline → invoice).
 *
 * PDF dapat di-render dalam 2 format:
 *   - RAB tabular (cocok untuk lampiran detail)
 *   - Surat Penawaran (letter layout dengan ringkasan total)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('quote_number', 32)->unique();          // RAB-2026/05/0001
            $t->string('title', 250);                          // "Penawaran Robot Forklift IBE-FL-01 untuk PT Telkomsel"
            $t->string('project_name', 250)->nullable();       // Internal project label

            // Customer snapshot (untuk freeze data kalau customer berubah)
            $t->foreignId('customer_id')->nullable()->index();
            $t->string('customer_name', 200);
            $t->text('customer_address')->nullable();
            $t->string('customer_pic_name', 150)->nullable();  // "Bpk. Ahmad / Ibu Siti"
            $t->string('customer_pic_title', 100)->nullable(); // "Procurement Manager"

            // Quote dates
            $t->date('quote_date');
            $t->date('valid_until')->nullable();               // Masa berlaku penawaran

            // Optional CRM link
            $t->foreignId('crm_deal_id')->nullable()->index();

            // Totals (computed from items)
            $t->decimal('subtotal',     16, 2)->default(0);
            $t->decimal('discount',     16, 2)->default(0);
            $t->decimal('discount_pct',  5, 2)->default(0);
            $t->decimal('after_discount',16, 2)->default(0);
            $t->decimal('tax_rate',      5, 2)->default(11);   // PPN 11%
            $t->decimal('tax_amount',   16, 2)->default(0);
            $t->decimal('total',        16, 2)->default(0);

            // Terms
            $t->text('payment_terms')->nullable();             // e.g. "30% DP, 70% setelah serah terima"
            $t->text('delivery_terms')->nullable();            // e.g. "Pengiriman dalam 14 hari kerja"
            $t->text('warranty_terms')->nullable();            // e.g. "Garansi 12 bulan"
            $t->text('notes')->nullable();                     // Catatan/lampiran lainnya
            $t->text('preamble')->nullable();                  // Paragraf pembuka di surat penawaran

            // Workflow
            $t->string('status', 16)->default('draft');        // draft|sent|accepted|rejected|expired
            $t->ulid('prepared_by')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->text('rejection_reason')->nullable();

            // Link ke invoice kalau accepted
            $t->foreignId('fin_invoice_id')->nullable()->index();

            $t->softDeletes();
            $t->timestamps();
            $t->index(['company_id', 'status', 'quote_date']);
        });

        Schema::create('quotation_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quotation_id')->index();
            $t->string('section_label', 100)->nullable();      // "I. Pekerjaan Persiapan"
            $t->integer('sort_order')->default(0);
            $t->foreignId('product_id')->nullable();           // optional link to products catalog
            $t->text('description');                           // Uraian pekerjaan/barang
            $t->decimal('qty',       14, 4)->default(1);
            $t->string('unit', 24)->default('unit');           // Satuan: unit, set, m, m², ls, hari, paket
            $t->decimal('unit_price',16, 2)->default(0);       // Harga Satuan
            $t->decimal('subtotal',  16, 2)->default(0);       // Jumlah = qty × unit_price
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['quotation_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
