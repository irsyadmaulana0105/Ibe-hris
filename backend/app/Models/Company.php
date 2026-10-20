<?php

namespace App\Models;

use App\Services\EnterpriseRbacProvisioningService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'short_name',
        'logo_url',
        'logo_dark_url',
        'favicon_url',
        'brand_color_primary',
        'brand_color_accent',
        'tagline',
        'slug',
        'logo',
        'address',
        'city',
        'province',
        'regency',
        'district',
        'village',
        'postal_code',
        'phone',
        'email',
        'finance_email',
        'npwp',
        'nib',
        'finance_signature_path',
        'finance_signature_sha256',
        'hrd_signature_path',
        'hrd_signature_sha256',
        'director_signature_path',
        'director_signature_sha256',
        'director_name',
        'stamp_path',
        'stamp_sha256',
        'website',
        'letterhead_style',
        'contract_watermark_text',
        'contract_watermark_color',
        'contract_watermark_opacity',
        'is_active',
        'late_grace_default_minutes',
        'attendance_bonus_min_present',
        'attendance_bonus_max_late',
    ];

    protected $hidden = [
        'finance_signature_path',
        'finance_signature_sha256',
        'hrd_signature_path',
        'hrd_signature_sha256',
        'director_signature_path',
        'director_signature_sha256',
        'stamp_path',
        'stamp_sha256',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'late_grace_default_minutes' => 'integer',
            'attendance_bonus_min_present' => 'integer',
            'attendance_bonus_max_late' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Company $company): void {
            app(EnterpriseRbacProvisioningService::class)
                ->provisionCompany((int) $company->getKey());
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function divisions(): HasMany
    {
        return $this->hasMany(Division::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(CompanySubscription::class);
    }

    public function moduleEntitlements(): HasMany
    {
        return $this->hasMany(CompanyModuleEntitlement::class);
    }

    public function getLogoUrlAttribute(?string $value): ?string
    {
        if ($value) {
            return $value;
        }
        if (! $this->logo) {
            return null;
        }

        return Storage::disk('public')->url($this->logo);
    }

    /**
     * Resolve tenant branding while keeping a complete, stable response shape.
     * Empty company fields deliberately fall back to the configured platform brand.
     *
     * @return array{name:string,short_name:string,tagline:string,logo_url:string,logo_dark_url:?string,favicon_url:string,brand_color_primary:string,brand_color_accent:string}
     */
    public function brandingArray(): array
    {
        $default = config('branding.default');

        return [
            'name' => $this->name ?: $default['name'],
            'short_name' => $this->short_name ?: ($this->name ?: $default['short_name']),
            'tagline' => $this->tagline ?: $default['tagline'],
            'logo_url' => $this->logo_url ?: $default['logo_url'],
            'logo_dark_url' => $this->logo_dark_url ?: $default['logo_dark_url'],
            'favicon_url' => $this->favicon_url ?: $default['favicon_url'],
            'brand_color_primary' => $this->brand_color_primary ?: $default['brand_color_primary'],
            'brand_color_accent' => $this->brand_color_accent ?: $default['brand_color_accent'],
        ];
    }
}
