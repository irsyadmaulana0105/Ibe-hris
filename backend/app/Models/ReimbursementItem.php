<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReimbursementItem extends Model
{
    use HasFactory;

    protected $fillable = ['reimbursement_id', 'user_id', 'description', 'amount'];

    public function reimbursement(): BelongsTo
    {
        return $this->belongsTo(Reimbursement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
