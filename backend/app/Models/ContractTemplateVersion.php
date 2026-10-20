<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractTemplateVersion extends Model
{
    protected $fillable = [
        'template_id', 'version', 'title', 'body_html',
        'source_type', 'snapshot_by', 'snapshot_reason', 'snapshot_at',
    ];

    protected $casts = [
        'snapshot_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'template_id');
    }

    public function snapshotter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'snapshot_by');
    }
}
