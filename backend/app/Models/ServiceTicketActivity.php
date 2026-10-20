<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceTicketActivity extends Model
{
    public $timestamps = false;
    protected $table = 'service_ticket_activities';

    protected $fillable = [
        'complaint_id', 'company_id', 'type', 'actor_user_id', 'actor_label',
        'body', 'meta', 'is_internal', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta'        => 'array',
            'is_internal' => 'boolean',
            'created_at'  => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Complaint::class, 'complaint_id');
    }
}
