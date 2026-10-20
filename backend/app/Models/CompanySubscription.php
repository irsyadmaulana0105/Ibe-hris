<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySubscription extends Model
{
    protected $fillable = ['company_id', 'plan_id', 'status', 'licensed_users', 'trial_ends_at', 'current_period_ends_at', 'external_reference', 'billing_email'];

    protected function casts(): array
    {
        return ['licensed_users' => 'integer', 'trial_ends_at' => 'datetime', 'current_period_ends_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'plan_id');
    }
}
