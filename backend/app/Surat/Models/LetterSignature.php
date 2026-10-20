<?php

namespace App\Surat\Models;

use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterSignature extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'user_id', 'name', 'title', 'email',
        'prepared_by_id', 'approved_by_id', 'approved_at',
        'signature_path', 'stamp_path',
        'default_for_categories', 'is_active', 'is_company_default',
    ];

    protected $hidden = ['signature_path', 'stamp_path'];

    protected function casts(): array
    {
        return [
            'default_for_categories' => 'array',
            'is_active' => 'boolean',
            'is_company_default' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public static function booted(): void
    {
        // Defense-in-depth: cegah path traversal di signature_path / stamp_path.
        // Hanya boleh path privat yang terikat ke company model - kalau tidak,
        // throw exception agar tidak ke-write ke DB.
        $validatePath = function (LetterSignature $sig, string $field, ?string $path): ?string {
            if ($path === null || $path === '') {
                return null;
            }
            $normalized = ltrim($path, '/');
            $expectedPrefix = 'private/letters/signatures/'.(int) $sig->company_id.'/';
            if (! str_starts_with($normalized, $expectedPrefix)) {
                throw new \InvalidArgumentException("LetterSignature.{$field} harus berada di penyimpanan privat tenant.");
            }
            // Block traversal: ../ atau backslash atau null byte
            if (preg_match('#(\.\.|\\\\|\x00)#', $normalized)) {
                throw new \InvalidArgumentException("LetterSignature.{$field} mengandung karakter tidak aman.");
            }

            return $normalized;
        };

        static::saving(function (LetterSignature $sig) use ($validatePath) {
            if ($sig->isDirty('signature_path')) {
                $sig->signature_path = $validatePath($sig, 'signature_path', $sig->signature_path);
            }
            if ($sig->isDirty('stamp_path')) {
                $sig->stamp_path = $validatePath($sig, 'stamp_path', $sig->stamp_path);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
