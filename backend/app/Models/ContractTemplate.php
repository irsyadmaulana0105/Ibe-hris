<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractTemplate extends Model
{
    protected $fillable = [
        'company_id', 'employment_type', 'title', 'body_html',
        'source_type', 'source_docx_path', 'variables',
        'is_active', 'version', 'created_by', 'updated_by',
    ];

    protected $hidden = ['source_docx_path'];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Detect semua `{{variable}}` tokens di body_html.
     * Return array of unique variable names, in order of first appearance.
     */
    public function detectVariables(): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $this->body_html ?? '', $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
