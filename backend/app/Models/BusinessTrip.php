<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessTrip extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'user_id', 'shift_id', 'date',
        'check_in_time', 'check_in_latitude', 'check_in_longitude', 'check_in_photo', 'late_minutes',
        'check_out_time', 'check_out_latitude', 'check_out_longitude', 'check_out_photo', 'early_out_minutes',
        'destination', 'purpose', 'status', 'notes', 'approved_by', 'approved_at',
    ];

    protected $hidden = ['check_in_photo', 'check_out_photo'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
