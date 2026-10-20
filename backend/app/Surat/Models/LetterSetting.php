<?php

namespace App\Surat\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LetterSetting extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'logo_path',
        'company_name', 'brand_name', 'tagline',
        'address', 'phone', 'email', 'website',
        'kop_color',
    ];

    public static function forCompany(int $companyId): self
    {
        return self::firstOrCreate(
            ['company_id' => $companyId],
            self::defaults(),
        );
    }

    /** Read settings without mutating storage on a GET request. */
    public static function readForCompany(int $companyId): self
    {
        return self::query()->where('company_id', $companyId)->first()
            ?? new self(array_merge(['company_id' => $companyId], self::defaults()));
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'company_name' => 'PT INTEGRASI BISNIS EKSEKUTIF',
            'brand_name' => 'IBE REALITY',
            'tagline' => 'Software · System Integration · Digital Solutions',
            'address' => 'Sidoarjo, Jawa Timur, Indonesia',
            'phone' => '+62 813-3005-8541',
            'email' => 'admin@ibereality.com',
            'website' => 'www.ibereality.com',
            'kop_color' => '#0d9488',
        ];
    }

    public static function booted(): void
    {
        // Cegah path traversal di logo_path - sama strategi dengan LetterSignature.
        static::saving(function (LetterSetting $s) {
            if ($s->isDirty('logo_path') && $s->logo_path) {
                $p = ltrim($s->logo_path, '/');
                if (! str_starts_with($p, 'letters/logo/')) {
                    throw new \InvalidArgumentException("logo_path harus di 'letters/logo/...'");
                }
                if (preg_match('#(\.\.|\\\\|\x00)#', $p)) {
                    throw new \InvalidArgumentException('logo_path mengandung karakter tidak aman.');
                }
                $s->logo_path = $p;
            }
        });
    }
}
