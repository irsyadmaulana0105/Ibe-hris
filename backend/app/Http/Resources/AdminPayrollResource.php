<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminPayrollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_number' => $this->payroll_number,
            'period' => [
                'month' => $this->month,
                'year' => $this->year,
                'label' => $this->period_label,
                'start' => $this->period_start?->toDateString(),
                'end' => $this->period_end?->toDateString(),
            ],
            'base_salary' => (int) $this->base_salary,
            'gross_total' => (int) $this->gross_total,
            'deduction_total' => (int) $this->deduction_total,
            'net_total' => (int) $this->net_total,
            'attendance_count' => (int) $this->attendance_count,
            'attendance_percentage' => (float) $this->attendance_percentage,
            'attendance_total' => (int) $this->attendance_total,
            'attendance_bonus_note' => $this->attendance_bonus_note,
            'late_count' => (int) $this->late_count,
            'late_total' => (int) $this->late_total,
            'overtime_total' => (int) $this->overtime_total,
            'reimbursement_total' => (int) $this->reimbursement_total,
            'salary_advance_payment' => (int) $this->salary_advance_payment,
            'status' => $this->status,
            'transfer_proof_uploaded' => !empty($this->transfer_proof_path),
            'transfer_proof_uploaded_at' => $this->transfer_proof_uploaded_at?->toIso8601String(),
            'transfer_reference' => $this->transfer_reference,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'nip' => $this->user?->nip,
                'email' => $this->user?->email,
                'bank_account' => $this->user?->bank_account,
                'division' => $this->user?->division?->name,
                'job_title' => $this->user?->jobTitle?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
