<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BackupSnapshot extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'ran_at', 'size_bytes', 'type', 'status', 'storage_path',
    ];

    protected function casts(): array
    {
        return ['ran_at' => 'datetime'];
    }
}
