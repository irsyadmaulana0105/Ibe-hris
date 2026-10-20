<?php

namespace App\Surat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterAttachment extends Model
{
    public $timestamps = false;

    protected $hidden = ['path'];

    protected $fillable = [
        'letter_id', 'original_name', 'path', 'mime_type',
        'size_bytes', 'checksum', 'uploaded_by_id',
    ];
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }
    public function letter(): BelongsTo     { return $this->belongsTo(Letter::class); }
    public function uploadedBy(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by_id'); }
}
