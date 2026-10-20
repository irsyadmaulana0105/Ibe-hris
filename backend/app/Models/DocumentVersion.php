<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    public $timestamps = false;
    protected $table = 'document_versions';

    protected $fillable = [
        'document_id', 'version_label', 'file_path', 'original_filename',
        'mime_type', 'file_size_bytes', 'file_hash_sha256', 'change_notes',
        'is_current', 'uploaded_by', 'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'is_current'      => 'boolean',
            'uploaded_at'     => 'datetime',
            'file_size_bytes' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CompanyDocument::class, 'document_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
