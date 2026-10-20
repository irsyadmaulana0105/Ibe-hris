<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Education extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasTimestamps;

    protected $table = 'educations';

    protected $fillable = [
        'company_id',
        'name'
    ];
   public function users()
    {
        return $this->hasMany(\App\Models\User::class);
    }

}
