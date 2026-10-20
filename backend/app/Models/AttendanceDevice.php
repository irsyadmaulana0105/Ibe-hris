<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDevice extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'brand', 'model', 'serial_number',
        'ip_address', 'port', 'protocol', 'office_location_id',
        'status', 'last_sync_at', 'total_employees_synced',
        'enabled', 'notes', 'push_secret',
    ];

    protected $hidden = ['push_secret'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_sync_at' => 'datetime',
            'push_secret' => 'encrypted',
        ];
    }

    public function officeLocation(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class);
    }
}
