<?php

namespace App\Services;

use App\Models\Division;
use App\Models\Education;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    public function paginateForAdmin(User $admin, array $filters = []): LengthAwarePaginator
    {
        $query = User::query()
            ->where('company_id', $admin->company_id)
            ->where('group', 'user')
            ->with(['division:id,name', 'jobTitle:id,name', 'education:id,name'])
            ->orderBy('name');

        // Default: hide karyawan resigned/terminated dari list. Admin bisa
        // explicit ambil dengan `?include_inactive=1` atau filter
        // `employment_status=resigned` untuk lihat history.
        $includeInactive = ! empty($filters['include_inactive'])
            || (! empty($filters['employment_status'])
                && in_array($filters['employment_status'], User::INACTIVE_STATUSES, true));
        if (! $includeInactive) {
            $query->whereNotIn('employment_status', User::INACTIVE_STATUSES);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['division_id'])) {
            $query->where('division_id', $filters['division_id']);
        }

        if (! empty($filters['job_title_id'])) {
            $query->where('job_title_id', $filters['job_title_id']);
        }

        if (! empty($filters['education_id'])) {
            $query->where('education_id', $filters['education_id']);
        }

        if (! empty($filters['employment_status'])) {
            $query->where('employment_status', $filters['employment_status']);
        }

        // Training review filter - dashboard widget filter ke 'pending_review'
        if (! empty($filters['training_review_status'])) {
            $query->where('training_review_status', $filters['training_review_status']);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function findForAdmin(User $admin, string $id): User
    {
        return User::query()
            ->where('company_id', $admin->company_id)
            ->where('group', 'user')
            ->with(['division:id,name', 'jobTitle:id,name', 'education:id,name'])
            ->findOrFail($id);
    }

    public function referencesForAdmin(User $admin): array
    {
        return [
            'divisions' => Division::withoutGlobalScopes()
                ->where('company_id', $admin->company_id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'job_titles' => JobTitle::withoutGlobalScopes()
                ->whereHas('division', fn ($builder) => $builder->where('company_id', $admin->company_id))
                ->with('division:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'division_id']),
            'educations' => Education::withoutGlobalScopes()
                ->where('company_id', $admin->company_id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'employment_statuses' => User::EMPLOYMENT_STATUSES,
        ];
    }

    /**
     * Auto-generate NIP format: IBE-{YYYY}-{XXXX}, unik global per tahun.
     * Lock with DB transaction supaya thread-safe saat bulk import.
     */
    public function generateEmployeeNumber(int $companyId): string
    {
        $year = (int) date('Y');
        $prefix = "IBE-{$year}-";

        return \DB::transaction(function () use ($prefix) {
            $last = User::withoutGlobalScopes()
                ->where('employee_number', 'like', $prefix.'%')
                ->orderByDesc('employee_number')
                ->lockForUpdate()
                ->value('employee_number');
            $seq = 1;
            if ($last) {
                $suffix = substr($last, strlen($prefix));
                $seq = ((int) $suffix) + 1;
            }

            return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }

    public function validationRules(User $admin, ?User $employee = null): array
    {
        // Explicit pass ID + primary key column - `ignore($model)` kadang gagal
        // resolve key kalau pakai ULID/non-incrementing key.
        $ignoreId = $employee?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('users', 'name')->ignore($ignoreId, 'id')],
            'nip' => ['nullable', 'string', 'max:255'],
            'npwp' => ['nullable', 'string', 'max:20'],
            'ktp_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]{16}$/'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignoreId, 'id')],
            'phone' => ['nullable', 'string', 'min:5', 'max:255'],
            'password' => ['nullable', 'string', 'min:4', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'province' => ['nullable', 'string', 'max:100'],
            'regency' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'village' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'birth_date' => ['nullable', 'date'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'division_id' => ['nullable', 'integer'],
            'education_id' => ['nullable', 'integer'],
            'job_title_id' => ['nullable', 'integer'],
            'default_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'employment_status' => ['nullable', Rule::in(array_keys(User::EMPLOYMENT_STATUSES))],
            'salary_monthly' => ['nullable', 'integer', 'min:0'],
            // Training fields - hanya relevant kalau employment_status='training'
            'daily_rate' => ['nullable', 'integer', 'min:0'],
            'training_started_at' => ['nullable', 'date'],
            'training_ends_at' => ['nullable', 'date', 'after_or_equal:training_started_at'],
            'training_review_status' => ['nullable', Rule::in(['active', 'pending_review', 'reviewed'])],
            'bank_account' => ['nullable', 'string', 'max:255'],
            'join_date' => ['nullable', 'date'],
            'annual_leave_balance' => ['nullable', 'integer', 'min:0', 'max:255'],
            // Hanya superadmin yang boleh assign role non-default. Admin biasa
            // (HR) hanya bisa bikin karyawan (group=user) - escalate prevention.
            'group' => ['nullable', Rule::in(User::$groups)],
        ];
    }

    /**
     * Create a new employee and generate a one-time invite token.
     * Returns [User $employee, string $plainInviteToken].
     * raw_password is intentionally never stored.
     */
    public function create(User $admin, array $payload): array
    {
        app(ModuleEntitlementService::class)->assertUserCapacity((int) $admin->company_id);
        $data = $this->normalizePayload($admin, $payload);

        // If admin sets a password, use it; otherwise a random opaque password is set
        // (employee will use the invite token to choose their own password).
        $password = ! empty($payload['password'])
            ? (string) $payload['password']
            : Str::random(24);

        // If admin did NOT supply an email, try Stalwart auto-provision from first
        // name (e.g. "Aji Sapta" → aji@ibereality.com). Stalwart failure is
        // non-fatal: employee still created, mailbox provisioning logged as
        // warning.
        $mailboxCreds = null;
        $shouldProvisionMailbox = empty($data['email']);
        if ($shouldProvisionMailbox) {
            $stalwart = app(StalwartMailClient::class);
            $localPart = $stalwart->slugifyFirstName($data['name'] ?? '');
            if ($localPart) {
                $domain = config('services.stalwart.domain', 'ibereality.com');
                // Tentative email; real provisioning happens after the row exists
                $data['email'] = "{$localPart}@{$domain}";
            }
        }

        // Group: superadmin boleh set role apapun; selain itu paksa 'user'
        // supaya HR tidak bisa elevate role karyawan jadi admin/finance/produksi.
        $requestedGroup = $payload['group'] ?? null;
        $group = ($admin->group === 'superadmin' && $requestedGroup && in_array($requestedGroup, User::$groups, true))
            ? $requestedGroup
            : 'user';

        // NIP internal baru dipisahkan dari kolom nip legacy yang pada data lama
        // berisi NIK/ID mesin attendance.
        $data['employee_number'] = $this->generateEmployeeNumber((int) $admin->company_id);

        $employee = User::create([
            ...$data,
            'company_id' => $admin->company_id,
            'group' => $group,
            'password' => Hash::make($password),
        ]);

        // Provision Stalwart mailbox AFTER user is persisted so collisions check
        // works against the real users table.
        if ($shouldProvisionMailbox && ! empty($employee->email)) {
            $mailboxCreds = app(StalwartMailClient::class)->provisionForUser($employee);
            if ($mailboxCreds) {
                $updates = ['mailbox_password_encrypted' => \Crypt::encryptString($mailboxCreds['password'])];
                if ($mailboxCreds['email'] !== $employee->email) {
                    // Collision adjustment - slug had to be uniquified
                    $updates['email'] = $mailboxCreds['email'];
                }
                $employee->update($updates);
            }
        }

        $inviteToken = app(InviteTokenService::class)->generate($employee);

        app(AuditLogService::class)->log(
            'employee.created',
            $admin,
            'user',
            $employee->id,
            [
                'target_user_id' => $employee->id,
                'email' => $employee->email,
                'mailbox_created' => (bool) $mailboxCreds,
                'division_id' => $employee->division_id,
                'job_title_id' => $employee->job_title_id,
            ]
        );
        app(WebhookService::class)->publish((int) $admin->company_id, 'employee.created', [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'name' => $employee->name,
            'email' => $employee->email,
            'employment_status' => $employee->employment_status,
        ], app()->bound('correlation_id') ? (string) app('correlation_id') : null);

        return [
            $this->findForAdmin($admin, $employee->id),
            $inviteToken,
            $mailboxCreds, // [email, password] for the welcome email; null if not created
        ];
    }

    public function update(User $admin, string $id, array $payload): User
    {
        $employee = $this->findForAdmin($admin, $id);
        $before = $employee->only([
            'name', 'nip', 'email', 'phone', 'gender', 'city', 'address',
            'birth_date', 'birth_place', 'division_id', 'education_id', 'job_title_id',
            'employment_status', 'salary_monthly', 'join_date', 'annual_leave_balance',
        ]);

        // Hanya update field yang DIKIRIM di payload - pakai array_key_exists
        // (bukan ?? null) supaya partial update tidak kosongkan field yang
        // tidak di-touch user. Validate FK consistency kalau division/education/
        // job_title disertakan.
        $allowed = [
            'nip', 'npwp', 'ktp_number', 'name', 'email', 'phone', 'gender',
            'city', 'address', 'province', 'regency', 'district', 'village', 'postal_code',
            'birth_date', 'birth_place', 'division_id', 'education_id', 'job_title_id',
            'default_shift_id', 'employment_status', 'salary_monthly', 'bank_account', 'join_date',
            'daily_rate', 'training_started_at', 'training_ends_at', 'training_review_status',
            'annual_leave_balance', 'marital_status',
        ];
        $data = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = $payload[$key];
            }
        }

        // Group hanya superadmin yang boleh set - anti-escalation
        if (array_key_exists('group', $payload) && $admin->group === 'superadmin') {
            $requested = $payload['group'];
            if (in_array($requested, User::$groups, true)) {
                $data['group'] = $requested;
            }
        }

        // Validate FK consistency kalau ada
        if (array_key_exists('division_id', $data)) {
            $this->assertDivisionBelongsToCompany($admin, $this->nullableInt($data['division_id']));
        }
        if (array_key_exists('education_id', $data)) {
            $this->assertEducationBelongsToCompany($admin, $this->nullableInt($data['education_id']));
        }
        if (array_key_exists('job_title_id', $data)) {
            $this->assertJobTitleBelongsToCompany($admin, $this->nullableInt($data['job_title_id']));
        }

        if (! empty($payload['password'])) {
            $data['password'] = Hash::make((string) $payload['password']);
        }

        $employee->update($data);

        // Deprovision Stalwart mailbox when employee resigns/terminated.
        // Only applies to group=user; admin/finance/superadmin have manual mailboxes.
        $statusChanged = isset($data['employment_status']) && $data['employment_status'] !== ($before['employment_status'] ?? null);
        $isOffboarded = in_array($data['employment_status'] ?? '', ['resigned', 'terminated'], true);
        if ($statusChanged && $isOffboarded && $employee->group === 'user' && $employee->email) {
            $deprovisioned = app(StalwartMailClient::class)->deprovision($employee->email);
            $employee->update(['mailbox_password_encrypted' => null]);
            app(AuditLogService::class)->log(
                'employee.mailbox_deprovisioned',
                $admin,
                'user',
                $employee->id,
                ['email' => $employee->email, 'success' => $deprovisioned],
            );
        }

        app(AuditLogService::class)->log(
            'employee.updated',
            $admin,
            'user',
            $employee->id,
            [
                'target_user_id' => $employee->id,
                'before' => $before,
                'after' => $employee->fresh()->only(array_keys($before)),
                'password_changed' => ! empty($payload['password']),
            ]
        );

        $eventType = $statusChanged && $isOffboarded ? 'employee.resigned' : 'employee.updated';
        app(WebhookService::class)->publish((int) $admin->company_id, $eventType, [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'changed_fields' => array_keys($data),
            'employment_status' => $employee->employment_status,
        ], app()->bound('correlation_id') ? (string) app('correlation_id') : null);

        return $this->findForAdmin($admin, $employee->id);
    }

    private function normalizePayload(User $admin, array $payload): array
    {
        $divisionId = $this->nullableInt($payload['division_id'] ?? null);
        $educationId = $this->nullableInt($payload['education_id'] ?? null);
        $jobTitleId = $this->nullableInt($payload['job_title_id'] ?? null);

        $this->assertDivisionBelongsToCompany($admin, $divisionId);
        $this->assertEducationBelongsToCompany($admin, $educationId);
        $this->assertJobTitleBelongsToCompany($admin, $jobTitleId);

        // SEMUA field pakai `?? null` - backend dipanggil oleh update yang
        // bisa kirim sebagian field saja. Without `??` PHP error "Undefined
        // array key" saat field tidak ada di payload.
        return [
            'nip' => $payload['nip'] ?? null,
            'npwp' => $payload['npwp'] ?? null,
            'ktp_number' => $payload['ktp_number'] ?? null,
            'name' => $payload['name'] ?? null,
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'gender' => $payload['gender'] ?? null,
            'city' => $payload['city'] ?? null,
            'address' => $payload['address'] ?? null,
            'province' => $payload['province'] ?? null,
            'regency' => $payload['regency'] ?? null,
            'district' => $payload['district'] ?? null,
            'village' => $payload['village'] ?? null,
            'postal_code' => $payload['postal_code'] ?? null,
            'birth_date' => $payload['birth_date'] ?? null,
            'birth_place' => $payload['birth_place'] ?? null,
            'division_id' => $divisionId,
            'education_id' => $educationId,
            'job_title_id' => $jobTitleId,
            'default_shift_id' => $this->nullableInt($payload['default_shift_id'] ?? null),
            'group' => $payload['group'] ?? null,
            'employment_status' => $payload['employment_status'] ?? null,
            'salary_monthly' => $payload['salary_monthly'] ?? null,
            'join_date' => $payload['join_date'] ?? null,
            'annual_leave_balance' => $payload['annual_leave_balance'] ?? 12,
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function assertDivisionBelongsToCompany(User $admin, ?int $divisionId): void
    {
        if ($divisionId === null) {
            return;
        }

        $exists = Division::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('id', $divisionId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['division_id' => 'Division tidak valid untuk perusahaan aktif.']);
        }
    }

    private function assertEducationBelongsToCompany(User $admin, ?int $educationId): void
    {
        if ($educationId === null) {
            return;
        }

        $exists = Education::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('id', $educationId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['education_id' => 'Education tidak valid untuk perusahaan aktif.']);
        }
    }

    private function assertJobTitleBelongsToCompany(User $admin, ?int $jobTitleId): void
    {
        if ($jobTitleId === null) {
            return;
        }

        // Filter by company_id langsung (jangan via whereHas('division') yang
        // gagal kalau jabatan belum di-assign ke divisi spesifik).
        $exists = JobTitle::withoutGlobalScopes()
            ->where('id', $jobTitleId)
            ->where(function ($q) use ($admin) {
                $q->where('company_id', $admin->company_id)->orWhereNull('company_id');
            })
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['job_title_id' => 'Job title tidak valid untuk perusahaan aktif.']);
        }
    }
}
