<?php

namespace App\Surat\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterTemplate extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'category_id', 'code', 'name', 'description',
        'language', 'body_html', 'placeholders_schema', 'default_values',
        'is_system', 'active', 'version', 'display_order',
    ];

    protected function casts(): array
    {
        return [
            'placeholders_schema' => 'array',
            'default_values'      => 'array',
            'is_system'           => 'boolean',
            'active'              => 'boolean',
            'version'             => 'integer',
            'display_order'       => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LetterCategory::class, 'category_id');
    }
}
