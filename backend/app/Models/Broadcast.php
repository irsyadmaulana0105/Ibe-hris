<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Broadcast extends Model
{
    use HasUlids;
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'sent_by_user_id',
        'subject',
        'body',
        'priority',
        'audience_filter',
        'send_email',
        'recipient_count',
        'email_sent_count',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'audience_filter'   => 'array',
            'send_email'        => 'boolean',
            'recipient_count'   => 'integer',
            'email_sent_count'  => 'integer',
            'sent_at'           => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }
}
