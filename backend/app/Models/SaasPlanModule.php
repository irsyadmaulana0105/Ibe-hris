<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasPlanModule extends Model
{
    protected $fillable = ['plan_id', 'module_key', 'enabled', 'limits'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'limits' => 'array'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'plan_id');
    }
}
