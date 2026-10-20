<?php

namespace App\Marketing\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_activities';

    protected $fillable = [
        'company_id', 'type', 'subject', 'body',
        'subjectable_type', 'subjectable_id', 'user_id', 'happened_at',
    ];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime'];
    }

    public function subjectable(): MorphTo
    {
        return $this->morphTo();
    }
}
