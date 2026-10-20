<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'title', 'date', 'start_time', 'end_time',
        'location', 'agenda', 'type',
    ];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public static array $types = [
        'internal' => 'Internal',
        'external' => 'Eksternal',
        'board'    => 'Direksi',
        'other'    => 'Lainnya',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function minutes(): HasMany
    {
        return $this->hasMany(MeetingMinute::class);
    }
}
