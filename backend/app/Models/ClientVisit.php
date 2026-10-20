<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientVisit extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'user_id', 'date',
        'visit_in', 'photo_in', 'latitude_in', 'longitude_in', 'notes_in',
        'visit_out', 'photo_out', 'latitude_out', 'longitude_out', 'notes_out',
        'status',
    ];

    protected $hidden = ['photo_in', 'photo_out'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'visit_in' => 'datetime',
            'visit_out' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
