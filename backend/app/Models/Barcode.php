<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Barcode extends Model
{
    use HasFactory, HasTimestamps, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'value',
        'latitude',
        'longitude',
        'radius',
        'is_active',
    ];

    public function getLatLngAttribute(): ?array
    {
        if (is_null($this->latitude) || is_null($this->longitude)) {
            return null;
        }

        return [
            'lat' => $this->latitude,
            'lng' => $this->longitude,
        ];
    }
}
