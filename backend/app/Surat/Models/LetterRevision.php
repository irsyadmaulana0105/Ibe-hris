<?php

namespace App\Surat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'letter_id', 'version', 'status_before', 'status_after',
        'body_html_snapshot', 'body_data_snapshot',
        'changed_by_id', 'change_note',
    ];

    protected function casts(): array
    {
        return [
            'body_data_snapshot' => 'array',
            'created_at'         => 'datetime',
        ];
    }

    public function letter(): BelongsTo    { return $this->belongsTo(Letter::class); }
    public function changedBy(): BelongsTo { return $this->belongsTo(User::class, 'changed_by_id'); }
}
