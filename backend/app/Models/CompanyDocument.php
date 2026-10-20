<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyDocument extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'company_documents';

    protected $fillable = [
        'company_id', 'doc_code', 'title', 'category', 'description',
        'owner_dept', 'owner_user_id', 'tags', 'current_version',
        'effective_date', 'expiry_date', 'alert_days_before', 'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tags'           => 'array',
            'effective_date' => 'date',
            'expiry_date'    => 'date',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id')->orderByDesc('uploaded_at');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        if (!$this->expiry_date) return false;
        $days = now()->diffInDays($this->expiry_date, false);
        return $days >= 0 && $days <= (int) $this->alert_days_before;
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date && now()->gt($this->expiry_date);
    }
}
