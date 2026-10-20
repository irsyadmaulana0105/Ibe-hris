<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RbacCapability extends Model
{
    use BelongsToCompany;

    protected $table = 'rbac_capabilities';

    protected $fillable = ['company_id', 'module', 'capability_key', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(RbacRole::class, 'rbac_role_capability', 'capability_id', 'role_id');
    }
}
