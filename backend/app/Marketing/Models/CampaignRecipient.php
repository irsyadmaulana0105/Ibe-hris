<?php

namespace App\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sengaja tanpa BelongsToCompany: baris ini dibaca penjadwal (tanpa perusahaan
 * aktif) dan halaman berhenti berlangganan publik. Akses dari API selalu
 * melalui kampanye yang sudah disaring perusahaannya.
 */
class CampaignRecipient extends Model
{
    protected $table = 'crm_campaign_recipients';

    protected $fillable = [
        'campaign_id', 'company_id', 'email', 'name', 'organization',
        'source_type', 'source_id', 'status', 'token', 'sent_at', 'error',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id')->withoutGlobalScopes();
    }
}
