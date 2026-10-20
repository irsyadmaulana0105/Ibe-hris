<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BANT Lead Scoring framework (Budget / Authority / Need / Timeline).
 * Klasik dari IBM, dipake banyak CRM modern (HubSpot, Salesforce) untuk
 * qualify lead sebelum convert ke deal.
 *
 * Setiap dimensi 0-25, total 0-100. Convention:
 *   80-100 = "hot" - qualified, segera convert
 *   60-79  = "warm" - nurture, follow up
 *   40-59  = "cool" - info gathering
 *   0-39   = "cold" - disqualify or long-term
 *
 * Score = sum of 4 dimensions. Disimpan computed di kolom `score` supaya
 * gampang di-index untuk sort/filter list view.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $t) {
            // Budget: ada anggaran? berapa besar?
            $t->unsignedTinyInteger('budget_score')->default(0)->after('status');
            // Authority: pengambil keputusan? atau cuma influencer?
            $t->unsignedTinyInteger('authority_score')->default(0)->after('budget_score');
            // Need: tingkat kebutuhan / urgency masalah yang mereka punya
            $t->unsignedTinyInteger('need_score')->default(0)->after('authority_score');
            // Timeline: target closing/implementasi
            $t->unsignedTinyInteger('timeline_score')->default(0)->after('need_score');
            // Total (computed di app layer, di-cache di kolom untuk index)
            $t->unsignedTinyInteger('score')->default(0)->after('timeline_score')->index();
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $t) {
            $t->dropColumn(['budget_score', 'authority_score', 'need_score', 'timeline_score', 'score']);
        });
    }
};
