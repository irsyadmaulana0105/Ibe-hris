<?php

namespace App\Marketing\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'crm_campaigns';

    protected $fillable = [
        'company_id', 'name', 'subject', 'from_name', 'body', 'audience',
        'status', 'recipient_count', 'created_by', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class, 'campaign_id');
    }
}
