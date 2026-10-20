<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxStatus extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'ptkp_current', 'ptkp_2015', 'ptkp_2009'];

    protected function casts(): array
    {
        return [
            'ptkp_current' => 'decimal:2',
            'ptkp_2015'    => 'decimal:2',
            'ptkp_2009'    => 'decimal:2',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
