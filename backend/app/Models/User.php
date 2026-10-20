<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Services\EnterpriseAuthorizationService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use HasUlids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'nip',
        'employee_number',
        'npwp',
        'ktp_number',
        'name',
        'email',
        'legacy_email',
        'password',
        'group',
        'phone',
        'gender',
        'marital_status',
        'birth_date',
        'birth_place',
        'address',
        'city',
        'province',
        'regency',
        'district',
        'village',
        'postal_code',
        'education_id',
        'division_id',
        'job_title_id',
        'default_shift_id',
        'employee_grade_id',
        'tax_status_id',
        'office_location_id',
        'attendance_location_mode',
        'employment_status',
        'salary_monthly',
        'daily_rate',
        'training_started_at',
        'training_ends_at',
        'training_review_status',
        'bank_account',
        'meal_transport_rate',
        'overtime_rate',
        'attendance_allowance',
        'late_deduction',
        'absent_deduction',
        'leave_deduction',
        'bonus_rate',
        'thr_allowance',
        'salary_advance_balance',
        'annual_leave_balance',
        'join_date',
        'company_name',
        'company_address',
        'finance_email',
        'finance_signature_path',
        'hrd_signature_path',
        'director_signature_path',
        'profile_photo_path',
        'face_descriptor',
        'face_descriptor_pending',
        'invite_token',
        'invite_token_expires_at',
        'invite_accepted_at',
        'mailbox_password_encrypted',
        'project_email_notifications_enabled',
        // Resign/termination metadata
        'resigned_at',
        'last_working_date',
        'resignation_type',
        'resignation_reason',
        'resigned_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'mailbox_password_encrypted',
        'invite_token',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
        // Template biometrik tidak boleh ikut terserialisasi ke respons mana pun.
        'face_descriptor',
        'face_descriptor_pending',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'birth_date' => 'datetime:Y-m-d',
            'join_date' => 'datetime:Y-m-d',
            'password' => 'hashed',
            'invite_token_expires_at' => 'datetime',
            'invite_accepted_at' => 'datetime',
            'resigned_at' => 'datetime:Y-m-d',
            'last_working_date' => 'datetime:Y-m-d',
            'training_started_at' => 'datetime:Y-m-d',
            'training_ends_at' => 'datetime:Y-m-d',
            'face_quality_pending' => 'array',
        ];
    }

    /**
     * Keep generated avatars fully local. Jetstream's upstream fallback calls
     * ui-avatars.com with employee initials, which leaks identity metadata and
     * client network information whenever an SPA renders the URL.
     */
    protected function defaultProfilePhotoUrl(): string
    {
        $initials = trim(collect(explode(' ', (string) $this->name))
            ->filter()
            ->map(fn (string $segment) => mb_strtoupper(mb_substr($segment, 0, 1)))
            ->take(3)
            ->join(' '));
        $safeInitials = htmlspecialchars($initials !== '' ? $initials : 'U', ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">'
            .'<rect width="128" height="128" rx="24" fill="#e2e8f0"/>'
            .'<text x="64" y="70" text-anchor="middle" dominant-baseline="middle" '
            .'font-family="system-ui,sans-serif" font-size="38" font-weight="700" fill="#0f766e">'
            .$safeInitials.'</text></svg>';

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }

    /** True kalau status karyawan training (gaji harian). */
    public function getIsTrainingAttribute(): bool
    {
        return $this->employment_status === 'training';
    }

    /** True kalau periode training sudah lewat batas & HR belum review. */
    public function getNeedsTrainingReviewAttribute(): bool
    {
        return $this->is_training
            && $this->training_review_status === 'pending_review';
    }

    public static $groups = ['user', 'admin', 'superadmin', 'finance', 'produksi', 'direktur', 'marketing'];

    public const EMPLOYMENT_STATUSES = [
        'permanent' => 'Karyawan Tetap',
        'probation' => 'Karyawan Percobaan',
        'training' => 'Training (Gaji Harian)',
        'intern_campus' => 'Magang Kampus',
        'intern_hub' => 'Magang Hub',
        'pkl_smk' => 'PKL SMK',
        'resigned' => 'Resign',
        'terminated' => 'Diberhentikan',
    ];

    /** Status yang berarti user tidak lagi punya akses (off-boarded). */
    public const INACTIVE_STATUSES = ['resigned', 'terminated'];

    /**
     * Apakah user sudah off-boarded (resign/terminated) ATAU training expired
     * tanpa HR review. Sumber kebenaran tunggal - dipakai middleware,
     * AuthController, SsoService, refresh token rotation, list filter, dll.
     */
    final public function getIsInactiveAttribute(): bool
    {
        if (in_array($this->employment_status, self::INACTIVE_STATUSES, true)) {
            return true;
        }
        if ($this->resigned_at !== null) {
            return true;
        }
        // Training expired + belum di-review HR → block sampai HR action
        if ($this->employment_status === 'training' && $this->training_review_status === 'pending_review') {
            return true;
        }

        return false;
    }

    /** Pesan untuk ditampilkan ke user yang ditolak masuk. */
    final public function getInactiveReasonAttribute(): string
    {
        if ($this->employment_status === 'training' && $this->training_review_status === 'pending_review') {
            return 'Periode training Anda telah berakhir. Mohon tunggu konfirmasi HR untuk status kepegawaian selanjutnya.';
        }

        return match ($this->employment_status) {
            'resigned' => 'Akun Anda telah ditutup karena resign.',
            'terminated' => 'Akun Anda telah dinonaktifkan oleh administrator.',
            default => 'Akun Anda tidak aktif.',
        };
    }

    /**
     * Local scope: exclude karyawan yang sudah resign/terminated.
     * Pakai di semua list query yang seharusnya cuma menampilkan karyawan aktif:
     *   User::active()->where('company_id', $id)->get()
     */
    public function scopeActive($query)
    {
        return $query
            ->whereNotIn('employment_status', self::INACTIVE_STATUSES)
            ->whereNull('resigned_at');
    }

    /**
     * Local scope: include karyawan yang aktif sampai tanggal tertentu.
     * Pakai untuk payroll bulanan - karyawan resign mid-month TETAP dapat
     * payroll bulan itu (prorated dari attendance), tapi karyawan yang
     * resign-nya sebelum periode → skip.
     */
    public function scopeActiveDuring($query, Carbon $cutoff)
    {
        $cutoffDate = $cutoff->toDateString();

        return $query->where(function ($q) use ($cutoffDate) {
            $q->whereNull('resigned_at')
                ->orWhere('resigned_at', '>=', $cutoffDate);
        });
    }

    final public function getIsUserAttribute(): bool
    {
        return $this->group === 'user';
    }

    /** Hanya karyawan tetap yang bisa akses cuti, lembur, kasbon, slip gaji */
    final public function getIsPermanentAttribute(): bool
    {
        return $this->employment_status === 'permanent';
    }

    /** HR admin - manages employees, attendance, leave, overtime, etc. */
    final public function getIsHrAttribute(): bool
    {
        return $this->group === 'admin';
    }

    /** Finance admin - manages payroll only */
    final public function getIsFinanceAttribute(): bool
    {
        return $this->group === 'finance';
    }

    /** Any elevated role with access to admin panel */
    final public function getIsAdminAttribute(): bool
    {
        return in_array($this->group, ['admin', 'superadmin', 'finance', 'produksi', 'marketing'], true);
    }

    /** Tim Produksi - handle serial number & customer complaint */
    final public function getIsProduksiAttribute(): bool
    {
        return $this->group === 'produksi';
    }

    /** Marketing / CRM operator. */
    final public function getIsMarketingAttribute(): bool
    {
        return $this->group === 'marketing' || $this->hasRole('marketing');
    }

    final public function getIsSuperadminAttribute(): bool
    {
        return $this->group === 'superadmin';
    }

    final public function getIsNotAdminAttribute(): bool
    {
        return ! $this->isAdmin;
    }

    // ═══════════════════ MULTI-ROLE SYSTEM ═══════════════════
    // User bisa punya banyak roles simultan (primary dari users.group + additional
    // dari user_roles table). Active role dipilih di UI (localStorage) untuk filter
    // menu - TIDAK menambah power secara backend (capability check tetap real).

    public function roles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * Semua role yang user punya (primary + additional).
     * Selalu include `group` (primary) plus semua di user_roles.
     */
    public function getAvailableRolesAttribute(): array
    {
        $primary = $this->group;
        $additional = $this->roles()->pluck('role')->all();
        // Merge + unique + filter valid roles only
        $all = array_values(array_unique(array_merge([$primary], $additional)));

        return array_values(array_filter($all, fn ($r) => in_array($r, self::$groups, true)));
    }

    /** Cek apakah user punya role tertentu (primary atau additional). */
    public function hasRole(string $role): bool
    {
        if ($this->group === $role) {
            return true;
        }

        return $this->roles()->where('role', $role)->exists();
    }

    /** Grant role tambahan ke user. Idempotent - kalau sudah ada, skip. */
    public function assignRole(string $role, ?User $grantor = null, ?string $notes = null): void
    {
        if (! in_array($role, self::$groups, true)) {
            throw new \InvalidArgumentException("Invalid role: {$role}");
        }
        if ($this->group === $role) {
            return;
        }  // sudah primary, no-op

        UserRole::firstOrCreate(
            ['user_id' => $this->id, 'role' => $role],
            [
                'is_primary' => false,
                'granted_by' => $grantor?->id,
                'notes' => $notes,
                'granted_at' => now(),
            ],
        );
    }

    /** Revoke additional role - primary role tidak bisa di-revoke (harus ganti via update user.group). */
    public function revokeRole(string $role): void
    {
        if ($this->group === $role) {
            throw new \RuntimeException("Cannot revoke primary role '{$role}'. Change users.group instead.");
        }
        $this->roles()->where('role', $role)->delete();
    }

    public function capabilities(): array
    {
        $capabilities = match ($this->group) {
            'superadmin' => [
                'admin.access',
                'company.manage',
                'employees.manage',
                'attendance.manage',
                'leave.manage',
                'overtime.manage',
                'salary_advance.manage',
                'reimbursement.manage',
                'payroll.manage',     // legacy umbrella - implies all sub-caps
                'payroll.submit',
                'payroll.approve',
                'payroll.pay',
                'face_approval.manage',
                'master_data.manage',
                'reports.view',
                'executive.dashboard.view',
                'executive.approval.decide',
                'contract.final_sign',
                'audit_logs.view',
                'performance.manage',
                'recruitment.manage',
                'learning.manage',
                'rbac.manage',
                'leave_policy.manage',
                'workflow.manage',
                'notification.manage',
                'integration.manage',
                'backup.manage',
                'theme.manage',
                'broadcast.send',
                'finance.module.access',
                'treasury.payment.execute',
                'treasury.payment.approve',
                'treasury.reconciliation.prepare',
                'treasury.reconciliation.approve',
                // CRM module
                'crm.view', 'crm.manage', 'crm.admin',
                // Production module
                'production.view', 'production.manage', 'production.admin',
                'serial_number.manage',
                'complaint.manage',
                // Inventory module
                'inventory.view', 'inventory.manage', 'inventory.admin',
            ],
            'admin' => [
                'admin.access',
                'company.manage',
                'employees.manage',
                'attendance.manage',
                'leave.manage',
                'overtime.manage',
                'salary_advance.manage',
                'reimbursement.manage',
                'payroll.submit',     // HR can generate + ajukan to Finance
                'face_approval.manage',
                'master_data.manage',
                'reports.view',
                'audit_logs.view',    // HR perlu review jejak compliance / PII access
                'performance.manage',
                'recruitment.manage',
                'learning.manage',
                'leave_policy.manage',
                'workflow.manage',
                'notification.manage',
                'broadcast.send',
                // CRM module (HR also manages sales pipeline)
                'crm.view', 'crm.manage', 'crm.admin',
                // Production module
                'production.view', 'production.manage', 'production.admin',
                // Inventory module
                'inventory.view', 'inventory.manage', 'inventory.admin',
            ],
            'finance' => [
                'admin.access',
                'payroll.manage',     // Finance retains umbrella for legacy paths
                'payroll.approve',    // Finance approves submission
                'payroll.pay',        // Finance marks transferred
                'reports.view',
                'audit_logs.view',    // Finance audit own payroll/reimburse actions
                'finance.module.access',
                'treasury.payment.execute',
                'treasury.payment.approve',
                'treasury.reconciliation.prepare',
                // CRM read-only - Finance lihat pipeline untuk forecast cashflow
                'crm.view',
                // Inventory read-only - Finance lihat stock untuk costing
                'inventory.view',
            ],
            'produksi' => [
                'admin.access',
                'production.view', 'production.manage', 'production.admin',
                'inventory.view', 'inventory.manage',
                'serial_number.manage',
                'complaint.manage',
                'reports.view',
            ],
            'direktur' => [
                'admin.access',
                'reports.view',
                'executive.dashboard.view',
                'executive.approval.decide',
                'contract.final_sign',
            ],
            'marketing' => [
                'admin.access',
                'crm.view', 'crm.manage',
                'reports.view',
                'broadcast.send',
            ],
            default => [
                'self.service',
            ],
        };

        // Marketing is the first operational multi-role used in production:
        // Finance + Marketing keeps finance as primary while receiving only
        // the explicitly bounded CRM capabilities from the additional role.
        if ($this->group !== 'marketing' && $this->roles()->where('role', 'marketing')->exists()) {
            $capabilities = array_merge($capabilities, [
                'admin.access', 'crm.view', 'crm.manage', 'reports.view', 'broadcast.send',
            ]);
        }

        return array_values(array_unique($capabilities));
    }

    public function hasCapability(string $capability): bool
    {
        return app(EnterpriseAuthorizationService::class)
            ->allows($this, $capability);
    }

    /** Compatibility decision used by the enterprise RBAC shadow engine. */
    public function legacyHasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function education()
    {
        return $this->belongsTo(Education::class);
    }

    public function division()
    {
        return $this->belongsTo(Division::class);
    }

    public function jobTitle()
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function employeeGrade()
    {
        return $this->belongsTo(EmployeeGrade::class);
    }

    public function taxStatus()
    {
        return $this->belongsTo(TaxStatus::class);
    }

    public function officeLocation()
    {
        return $this->belongsTo(OfficeLocation::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaves()
    {
        return $this->hasMany(Leave::class);
    }

    public function overtimes()
    {
        return $this->hasMany(Overtime::class);
    }

    public function businessTrips()
    {
        return $this->hasMany(BusinessTrip::class);
    }

    public function clientVisits()
    {
        return $this->hasMany(ClientVisit::class);
    }

    public function salaryAdvances()
    {
        return $this->hasMany(SalaryAdvance::class);
    }

    public function reimbursements()
    {
        return $this->hasMany(Reimbursement::class);
    }

    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }

    public function disciplinaryLetters()
    {
        return $this->hasMany(DisciplinaryLetter::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function shiftMappings()
    {
        return $this->hasMany(ShiftMapping::class);
    }

    public function performanceReports()
    {
        return $this->hasMany(PerformanceReport::class);
    }
}
