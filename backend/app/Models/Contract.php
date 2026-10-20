<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    protected $fillable = [
        'user_id', 'company_id', 'contract_number', 'contract_type', 'template_id',
        'issued_at', 'start_date', 'end_date', 'monthly_amount',
        'status', 'placeholders', 'signature_path', 'signed_pdf_path',
        'signature_sha256', 'signed_pdf_sha256',
        'signed_at', 'signed_ip', 'issued_by',
        // HRD paraf
        'hrd_paraf_by', 'hrd_paraf_at', 'hrd_paraf_signature_path', 'hrd_paraf_notes',
        'hrd_paraf_signature_sha256',
        // Karyawan sign
        'karyawan_signature_path', 'karyawan_signed_at', 'karyawan_signed_ip',
        'karyawan_signature_sha256',
        // Direktur sign
        'direktur_signature_path', 'direktur_signed_by', 'direktur_signed_at',
        'direktur_signature_sha256',
        'direktur_signature_unavailable_at', 'direktur_signature_unavailable_reason',
        'direktur_signature_reference_fingerprint',
        // E-materai
        'materai_pdf_path', 'materai_uploaded_at', 'materai_uploaded_by',
        'materai_pdf_sha256',
        // Rejection
        'rejection_reason', 'rejected_by', 'rejected_at',
    ];

    protected $hidden = [
        'signature_path',
        'signature_sha256',
        'signed_pdf_path',
        'signed_pdf_sha256',
        'hrd_paraf_signature_path',
        'hrd_paraf_signature_sha256',
        'karyawan_signature_path',
        'karyawan_signature_sha256',
        'direktur_signature_path',
        'direktur_signature_sha256',
        'direktur_signature_reference_fingerprint',
        'materai_pdf_path',
        'materai_pdf_sha256',
    ];

    protected $casts = [
        'placeholders' => 'array',
        'issued_at' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'signed_at' => 'datetime',
        'hrd_paraf_at' => 'datetime',
        'karyawan_signed_at' => 'datetime',
        'direktur_signed_at' => 'datetime',
        'direktur_signature_unavailable_at' => 'datetime',
        'materai_uploaded_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    /**
     * Lifecycle states (new workflow, 2026-07-04):
     *   draft             - HRD baru generate, belum paraf
     *   paraf_hrd         - HRD sudah paraf, siap kirim ke karyawan
     *   pending_karyawan  - menunggu TTD karyawan
     *   signed_karyawan   - karyawan sudah TTD, siap kirim ke direktur
     *   pending_direktur  - menunggu TTD direktur
     *   signed_direktur   - direktur sudah TTD, siap upload materai
     *   pending_materai   - menunggu HRD upload PDF ber-materai
     *   archived          - final, siap arsipkan/download
     *   rejected          - dibatalkan
     *
     * Legacy states masih supported untuk contracts lama:
     *   pending_signature → petakan ke pending_karyawan
     *   signed            → petakan ke archived
     */
    public const STATE_DRAFT = 'draft';

    public const STATE_PARAF_HRD = 'paraf_hrd';

    public const STATE_PENDING_KARYAWAN = 'pending_karyawan';

    public const STATE_SIGNED_KARYAWAN = 'signed_karyawan';

    public const STATE_PENDING_DIREKTUR = 'pending_direktur';

    public const STATE_SIGNED_DIREKTUR = 'signed_direktur';

    public const STATE_PENDING_MATERAI = 'pending_materai';

    public const STATE_ARCHIVED = 'archived';

    public const STATE_REJECTED = 'rejected';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
