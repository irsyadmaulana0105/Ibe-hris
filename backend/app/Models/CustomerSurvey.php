<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CustomerSurvey extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'survey_token', 'customer_id', 'customer_name', 'customer_email',
        'trigger_type', 'trigger_ref_table', 'trigger_ref_id',
        'sent_date', 'responded_at', 'nps_score', 'nps_category', 'comment', 'ratings',
        'status', 'sent_by',
    ];

    protected function casts(): array
    {
        return [
            'sent_date'    => 'date',
            'responded_at' => 'datetime',
            'ratings'      => 'array',
            'nps_score'    => 'integer',
        ];
    }

    public static function categoryFor(int $score): string
    {
        return $score >= 9 ? 'promoter' : ($score >= 7 ? 'passive' : 'detractor');
    }
}
