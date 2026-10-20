<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderOutput extends Model
{
    public $timestamps = false;

    protected $fillable = ['work_order_id', 'serial_number_id', 'produced_at'];

    protected function casts(): array
    {
        return ['produced_at' => 'datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(ProductSerialNumber::class);
    }
}
