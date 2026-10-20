<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'title', 'category', 'provider', 'hours',
        'type', 'url', 'mandatory', 'description',
    ];

    protected function casts(): array
    {
        return ['mandatory' => 'boolean', 'hours' => 'integer'];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
