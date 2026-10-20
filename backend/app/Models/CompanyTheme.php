<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyTheme extends Model
{
    protected $fillable = [
        'company_id', 'primary_color', 'accent_color',
        'font_family', 'radius', 'logo_url',
    ];
}
