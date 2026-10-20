<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RbacRole extends Model
{
    use BelongsToCompany;

    protected $table = 'rbac_roles';

    protected $fillable = ['company_id', 'key', 'label', 'color', 'description', 'is_builtin'];

    protected function casts(): array
    {
        return ['is_builtin' => 'boolean'];
    }

    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(RbacCapability::class, 'rbac_role_capability', 'role_id', 'capability_id');
    }
}
