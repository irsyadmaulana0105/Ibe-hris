<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskAssignment extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'assignment_number', 'created_by',
        'date', 'title', 'details', 'status',
    ];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public static array $statuses = [
        'open'        => 'Terbuka',
        'in_progress' => 'Berjalan',
        'completed'   => 'Selesai',
        'cancelled'   => 'Dibatalkan',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TaskAssignmentItem::class);
    }
}
