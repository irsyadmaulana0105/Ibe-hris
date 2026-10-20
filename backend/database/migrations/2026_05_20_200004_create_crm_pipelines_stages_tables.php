<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Pipelines + Stages - kanban configurable per tenant.
 * Default pipeline "Sales" dibikin di seeder dengan stages:
 *   Prospecting → Qualification → Proposal → Negotiation → Closed Won | Closed Lost.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_pipelines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name', 100);
            $t->string('description', 200)->nullable();
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['company_id', 'is_default']);
        });

        Schema::create('crm_stages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pipeline_id');
            $t->string('name', 100);
            $t->string('color', 20)->default('#1a7a8c'); // hex untuk badge kanban
            // 'open'  = stage normal di tengah pipeline
            // 'won'   = stage akhir sukses (auto-trigger create fin_customer + invoice draft)
            // 'lost'  = stage akhir gagal
            $t->enum('type', ['open', 'won', 'lost'])->default('open');
            $t->unsignedInteger('sort_order')->default(0);
            // Win-probability default untuk forecast weighted (0-100)
            $t->unsignedTinyInteger('default_probability')->default(50);
            $t->timestamps();
            $t->index(['company_id', 'pipeline_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_stages');
        Schema::dropIfExists('crm_pipelines');
    }
};
