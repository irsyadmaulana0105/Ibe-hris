<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 extensions:
 *   - public_token on quotations (untuk public customer-facing accept link)
 *   - customer_responded_at + customer_response_note (jejak action customer)
 *   - email_sent_at + email_sent_to (jejak terakhir kirim email)
 *   - quotation_templates table (library section presets)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $t) {
            if (!Schema::hasColumn('quotations', 'public_token')) {
                $t->string('public_token', 64)->nullable()->unique()->after('quote_number');
                $t->timestamp('customer_responded_at')->nullable()->after('rejected_at');
                $t->text('customer_response_note')->nullable()->after('customer_responded_at');
                $t->string('email_sent_to', 200)->nullable()->after('customer_response_note');
                $t->timestamp('email_sent_at')->nullable()->after('email_sent_to');
            }
        });

        if (!Schema::hasTable('quotation_templates')) {
            Schema::create('quotation_templates', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->index();
                $t->string('name', 200);                           // "Instalasi Forklift Standard"
                $t->string('category', 64)->nullable();            // "robot" | "service" | "training" | "other"
                $t->text('description')->nullable();
                $t->json('items_template');                        // array of section + items
                $t->json('default_terms')->nullable();             // {payment, delivery, warranty, preamble}
                $t->boolean('is_active')->default(true);
                $t->integer('usage_count')->default(0);
                $t->ulid('created_by')->nullable();
                $t->softDeletes();
                $t->timestamps();
                $t->index(['company_id', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_templates');
        Schema::table('quotations', function (Blueprint $t) {
            foreach (['public_token', 'customer_responded_at', 'customer_response_note', 'email_sent_to', 'email_sent_at'] as $c) {
                if (Schema::hasColumn('quotations', $c)) $t->dropColumn($c);
            }
        });
    }
};
