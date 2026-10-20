<?php

namespace App\Surat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterDisposition extends Model
{
    protected $fillable = [
        'letter_id', 'from_user_id', 'to_user_id',
        'action_type', 'instruction', 'deadline',
        'completed', 'completed_at',
    ];
    protected function casts(): array
    {
        return [
            'deadline'     => 'date',
            'completed'    => 'boolean',
            'completed_at' => 'datetime',
        ];
    }
    public function letter(): BelongsTo   { return $this->belongsTo(Letter::class); }
    public function fromUser(): BelongsTo { return $this->belongsTo(User::class, 'from_user_id'); }
    public function toUser(): BelongsTo   { return $this->belongsTo(User::class, 'to_user_id'); }
}
