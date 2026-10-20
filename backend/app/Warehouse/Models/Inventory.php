<?php

namespace App\Warehouse\Models;

use App\Models\JobTitle;
use App\Models\OfficeLocation;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'item_code', 'item_name', 'stock', 'unit',
        'description', 'category_id', 'office_location_id', 'job_title_id',
    ];

    protected function casts(): array
    {
        return ['stock' => 'decimal:2'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function officeLocation(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }
}
