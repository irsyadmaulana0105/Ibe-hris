<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class AdminEmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nip' => $this->nip,
            'employee_number' => $this->employee_number,
            'nik' => $this->ktp_number ?: (preg_match('/^\d{16}$/', (string) $this->nip) ? $this->nip : null),
            'npwp' => $this->npwp,
            'ktp_number' => $this->ktp_number,
            'name' => $this->name,
            'email' => $this->email,
            'legacy_email' => $this->legacy_email,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'group' => $this->group,
            'is_owner' => (bool) ($this->is_owner ?? false),
            'is_permanent' => $this->employment_status === 'permanent',
            'employment_status' => $this->employment_status,
            'employment_status_label' => match ($this->employment_status) {
                'permanent' => 'Karyawan Tetap',
                'probation' => 'Karyawan Percobaan',
                'intern_campus' => 'Magang Kampus',
                'intern_hub' => 'Magang Hub',
                'pkl_smk' => 'PKL SMK',
                'resigned' => 'Resign',
                'terminated' => 'Diberhentikan',
                default => '-',
            },
            'is_inactive' => (bool) $this->is_inactive,
            'resigned_at' => $this->resigned_at?->toDateString(),
            'last_working_date' => $this->last_working_date?->toDateString(),
            'resignation_type' => $this->resignation_type,
            'resignation_reason' => $this->resignation_reason,
            'join_date' => $this->join_date?->toDateString(),
            'birth_date' => $this->birth_date?->toDateString(),
            'birth_place' => $this->birth_place,
            'address' => $this->address,
            'city' => $this->city,
            'province' => $this->province,
            'regency' => $this->regency,
            'district' => $this->district,
            'village' => $this->village,
            'postal_code' => $this->postal_code,
            'salary_monthly' => $this->salary_monthly,
            'daily_rate' => $this->daily_rate,
            'training_started_at' => $this->training_started_at?->toDateString(),
            'training_ends_at' => $this->training_ends_at?->toDateString(),
            'training_review_status' => $this->training_review_status,
            'bank_account' => $this->bank_account,
            'annual_leave_balance' => $this->annual_leave_balance ?? 12,
            'salary_advance_balance' => $this->salary_advance_balance,
            'avatar' => $this->profile_photo_url,
            'division' => $this->whenLoaded('division', fn () => $this->division ? [
                'id' => $this->division->id,
                'name' => $this->division->name,
                'default_shift_id' => $this->division->default_shift_id,
            ] : null),
            'job_title' => $this->whenLoaded('jobTitle', fn () => $this->jobTitle ? [
                'id' => $this->jobTitle->id,
                'name' => $this->jobTitle->name,
            ] : null),
            'default_shift_id' => $this->default_shift_id,
            'education' => $this->whenLoaded('education', fn () => $this->education ? [
                'id' => $this->education->id,
                'name' => $this->education->name,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
