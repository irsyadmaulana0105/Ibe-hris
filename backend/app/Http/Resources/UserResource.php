<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\ModuleEntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nip' => $this->nip,
            'employee_number' => $this->employee_number,
            'nik' => $this->ktp_number ?: (preg_match('/^\d{16}$/', (string) $this->nip) ? $this->nip : null),
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'group' => $this->group,
            'available_roles' => $this->available_roles,
            'employment_status' => $this->employment_status,
            'join_date' => $this->join_date?->toDateString(),
            'birth_date' => $this->birth_date?->toDateString(),
            'address' => $this->address,
            'city' => $this->city,
            'annual_leave_balance' => $this->annual_leave_balance ?? 12,
            'salary_advance_balance' => $this->salary_advance_balance,
            'avatar' => $this->profile_photo_url,

            'division' => $this->whenLoaded('division', fn () => ['id' => $this->division->id,  'name' => $this->division->name]),
            'job_title' => $this->whenLoaded('jobTitle', fn () => ['id' => $this->jobTitle->id,  'name' => $this->jobTitle->name]),
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'logo' => $this->company->logo_url,
                'address' => $this->company->address,
                'city' => $this->company->city,
                'phone' => $this->company->phone,
                'email' => $this->company->email,
                'finance_email' => $this->company->finance_email,
            ]),

            'permissions' => [
                'is_admin' => $this->isAdmin,
                'is_superadmin' => $this->isSuperadmin,
                'capabilities' => $this->capabilities(),
            ],

            // SaaS module visibility. Existing companies without entitlement
            // records receive the legacy all-enabled fallback.
            'module_entitlements' => app(ModuleEntitlementService::class)->forCompany($this->company_id),

            'has_face_registered' => ! empty($this->face_descriptor),
            'has_face_pending_approval' => ! empty($this->face_descriptor_pending) && empty($this->face_descriptor),
            'attendance_location_mode' => $this->attendance_location_mode ?: 'office_geofence',

            // Fitur terbatas: cuti, lembur, kasbon, slip gaji hanya untuk karyawan tetap
            'is_permanent' => $this->isPermanent,
        ];
    }
}
