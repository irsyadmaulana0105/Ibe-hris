<?php

namespace App\Marketing\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_pipelines';

    protected $fillable = ['company_id', 'name', 'description', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('sort_order');
    }
}
