<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payroll extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'user_id', 'payroll_number',
        'period_start', 'period_end', 'month', 'year', 'attendance_percentage',
        'base_salary', 'payroll_basis', 'daily_rate_snapshot', 'meal_transport_total',
        'overtime_count', 'overtime_rate', 'overtime_total',
        'attendance_count', 'attendance_rate', 'attendance_total', 'attendance_bonus_note',
        'bonus_count', 'bonus_rate', 'bonus_total',
        'thr_count', 'thr_rate', 'thr_total',
        'reimbursement_total',
        'late_count', 'late_rate', 'late_total',
        'absent_count', 'absent_rate', 'absent_total',
        'leave_count', 'leave_rate', 'leave_total',
        'salary_advance_balance', 'salary_advance_payment',
        'gross_total', 'deduction_total', 'net_total',
        'status',
        'generated_by', 'submitted_by', 'approved_by', 'paid_by',
        'transfer_proof_path', 'transfer_proof_uploaded_at', 'transfer_proof_uploaded_by', 'transfer_reference',
        'journal_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'attendance_percentage' => 'decimal:2',
            'transfer_proof_uploaded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getPeriodLabelAttribute(): string
    {
        if ($this->period_start && $this->period_end) {
            return $this->period_start->translatedFormat('d M Y')
                .' - '.$this->period_end->translatedFormat('d M Y');
        }

        $periodEnd = Carbon::create($this->year, $this->month, 27);
        $periodStart = Carbon::create($this->year, $this->month, 1)
            ->subMonthNoOverflow()
            ->day(28);

        return $periodStart->translatedFormat('d M Y')
            .' - '.$periodEnd->translatedFormat('d M Y');
    }
}
