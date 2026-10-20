<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Complaint extends Model
{
    use HasUlids;
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'ticket_number',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_company',
        'product_serial',
        'product_name',
        'category',
        'subject',
        'description',
        'attachments',
        'source',
        'priority',
        'sla_response_minutes',
        'sla_resolution_minutes',
        'target_response_at',
        'target_resolution_at',
        'first_response_at',
        'is_response_breached',
        'is_resolution_breached',
        'status',
        'assigned_to_user_id',
        'resolution_notes',
        'resolved_at',
        'resolved_by_user_id',
        'reopened_at',
        'reopen_count',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'attachments'             => 'array',
            'resolved_at'             => 'datetime',
            'target_response_at'      => 'datetime',
            'target_resolution_at'    => 'datetime',
            'first_response_at'       => 'datetime',
            'reopened_at'             => 'datetime',
            'is_response_breached'    => 'boolean',
            'is_resolution_breached'  => 'boolean',
        ];
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ServiceTicketActivity::class, 'complaint_id')->orderBy('created_at');
    }
}
