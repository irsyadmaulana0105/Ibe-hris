<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reimbursement extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'user_id', 'category_id', 'date',
        'event', 'unit_price', 'quantity', 'total_amount', 'approved_amount',
        'attachment_path', 'status', 'notes', 'approved_by', 'approved_at',
        'attachment_sha256', 'attachment_size_bytes', 'attachment_mime_type',
        'attachment_width', 'attachment_height',
        'attachment_unavailable_reason', 'attachment_reference_fingerprint',
        // Bank info (Step 1 - diisi karyawan saat submit)
        'bank_name', 'bank_account_number', 'bank_account_holder',
        // HR approval (Step 2)
        'hr_approved_by', 'hr_approved_at', 'hr_notes',
        // Finance settlement (Step 3)
        'settled_by', 'settled_at', 'payment_proof_path', 'payment_reference',
        'payment_proof_sha256', 'payment_proof_size_bytes', 'payment_proof_mime_type',
        'payment_proof_width', 'payment_proof_height',
        'payment_proof_unavailable_reason', 'payment_proof_reference_fingerprint',
        // Auto-generated kwitansi (Step 4)
        'receipt_number', 'receipt_pdf_path', 'journal_id',
        'receipt_pdf_sha256', 'receipt_pdf_size_bytes', 'receipt_pdf_mime_type',
        'receipt_pdf_width', 'receipt_pdf_height',
        'receipt_pdf_unavailable_reason', 'receipt_pdf_reference_fingerprint',
    ];

    protected $hidden = [
        'attachment_path',
        'attachment_sha256',
        'attachment_size_bytes',
        'attachment_mime_type',
        'attachment_width',
        'attachment_height',
        'attachment_unavailable_reason',
        'attachment_reference_fingerprint',
        'payment_proof_path',
        'payment_proof_sha256',
        'payment_proof_size_bytes',
        'payment_proof_mime_type',
        'payment_proof_width',
        'payment_proof_height',
        'payment_proof_unavailable_reason',
        'payment_proof_reference_fingerprint',
        'receipt_pdf_path',
        'receipt_pdf_sha256',
        'receipt_pdf_size_bytes',
        'receipt_pdf_mime_type',
        'receipt_pdf_width',
        'receipt_pdf_height',
        'receipt_pdf_unavailable_reason',
        'receipt_pdf_reference_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'approved_at' => 'datetime',
            'hr_approved_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ReimbursementCategory::class, 'category_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function hrApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_approved_by');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReimbursementItem::class);
    }
}
