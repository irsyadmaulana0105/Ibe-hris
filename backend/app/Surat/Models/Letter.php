<?php

namespace App\Surat\Models;

use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Letter extends Model
{
    use BelongsToCompany, SoftDeletes;

    /** Workflow states */
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_REVIEW    = 'review';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_SIGNED    = 'signed';
    public const STATUS_SENT      = 'sent';
    public const STATUS_ARCHIVED  = 'archived';
    public const STATUS_CANCELLED = 'cancelled';

    public const TRANSITIONS = [
        self::STATUS_DRAFT     => [self::STATUS_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_REVIEW    => [self::STATUS_APPROVED, self::STATUS_DRAFT, self::STATUS_CANCELLED],
        self::STATUS_APPROVED  => [self::STATUS_SIGNED, self::STATUS_DRAFT, self::STATUS_CANCELLED],
        self::STATUS_SIGNED    => [self::STATUS_SENT, self::STATUS_ARCHIVED],
        self::STATUS_SENT      => [self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED  => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'public_id', 'company_id', 'category_id', 'template_id',
        'letter_number', 'letter_number_seq', 'letter_number_year',
        'direction',
        'subject', 'purpose', 'target_code', 'body_html', 'body_data', 'attachments_meta',
        'recipient_name', 'recipient_title', 'recipient_company', 'recipient_address', 'recipient_email',
        'cc_list', 'parent_letter_id', 'qr_token',
        'sender_name', 'sender_company', 'received_date', 'original_number',
        'letter_date', 'expiry_date',
        'author_id', 'reviewer_id', 'approver_id', 'signer_id', 'cosigner_id', 'bank_id',
        'status', 'submitted_at', 'reviewed_at', 'approved_at', 'signed_at', 'sent_at', 'archived_at',
        'pdf_path', 'pdf_checksum',
        'internal_notes', 'confidential', 'tags',
    ];

    protected function casts(): array
    {
        return [
            'body_data'        => 'array',
            'attachments_meta' => 'array',
            'cc_list'          => 'array',
            'tags'             => 'array',
            'letter_date'      => 'date',
            'expiry_date'      => 'date',
            'received_date'    => 'date',
            'submitted_at'     => 'datetime',
            'reviewed_at'      => 'datetime',
            'approved_at'      => 'datetime',
            'signed_at'        => 'datetime',
            'sent_at'          => 'datetime',
            'archived_at'      => 'datetime',
            'confidential'     => 'boolean',
        ];
    }

    public static function booted(): void
    {
        static::creating(function (Letter $l) {
            if (empty($l->public_id)) {
                $l->public_id = (string) \Illuminate\Support\Str::ulid();
            }
            if (empty($l->qr_token)) {
                $l->qr_token = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function category(): BelongsTo  { return $this->belongsTo(LetterCategory::class, 'category_id'); }
    public function template(): BelongsTo  { return $this->belongsTo(LetterTemplate::class, 'template_id'); }
    public function author(): BelongsTo    { return $this->belongsTo(User::class, 'author_id'); }
    public function reviewer(): BelongsTo  { return $this->belongsTo(User::class, 'reviewer_id'); }
    public function approver(): BelongsTo  { return $this->belongsTo(User::class, 'approver_id'); }
    public function signer(): BelongsTo    { return $this->belongsTo(LetterSignature::class, 'signer_id'); }
    public function cosigner(): BelongsTo  { return $this->belongsTo(LetterSignature::class, 'cosigner_id'); }
    public function bank(): BelongsTo      { return $this->belongsTo(CompanyBank::class, 'bank_id'); }
    public function parentLetter(): BelongsTo { return $this->belongsTo(Letter::class, 'parent_letter_id'); }
    public function replies(): HasMany     { return $this->hasMany(Letter::class, 'parent_letter_id'); }
    public function attachments(): HasMany { return $this->hasMany(LetterAttachment::class)->orderBy('id'); }
    public function dispositions(): HasMany { return $this->hasMany(LetterDisposition::class)->latest(); }
    public function revisions(): HasMany   { return $this->hasMany(LetterRevision::class)->orderBy('version'); }

    public function canTransitionTo(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isLocked(): bool
    {
        return in_array($this->status, [self::STATUS_SIGNED, self::STATUS_SENT, self::STATUS_ARCHIVED, self::STATUS_CANCELLED], true);
    }
}
