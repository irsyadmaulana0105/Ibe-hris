<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequest extends Model
{
    protected $fillable = [
        'company_id', 'type', 'title', 'description', 'amount', 'attachments',
        'status', 'requested_by', 'approver_id', 'approver_notes',
        'approved_at', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'attachments' => 'array',
        'amount'      => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const TYPES = [
        'expense'         => 'Pengeluaran Besar',
        'vendor_contract' => 'Kontrak Vendor / Supplier',
        'hr_policy'       => 'Kebijakan HR Strategis',
        'other'           => 'Persetujuan Lainnya',
    ];

    public function requestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
