<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    use BelongsToCompany;

    public $timestamps = false;
    protected $table = 'app_notifications';

    protected $fillable = [
        'company_id', 'user_id', 'audience_role', 'type', 'severity',
        'title', 'body', 'link_url', 'ref_table', 'ref_id',
        'read_at', 'dismissed_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at'      => 'datetime',
            'dismissed_at' => 'datetime',
            'created_at'   => 'datetime',
        ];
    }
}
