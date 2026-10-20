<?php

namespace App\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

/** Alamat yang tidak boleh dikirimi kampanye lagi. Tanpa global scope - lihat CampaignRecipient. */
class EmailSuppression extends Model
{
    protected $table = 'crm_email_suppressions';

    protected $fillable = ['company_id', 'email', 'reason', 'campaign_id'];
}
