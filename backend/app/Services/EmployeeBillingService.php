<?php

namespace App\Services;

use App\Helpers;
use App\Mail\SalarySlipMail;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class EmployeeBillingService
{
    public function __construct(private readonly PrivateAssetStorage $privateAssets) {}

    public function buildBillingSummary(User $user): array
    {
        $period = $this->getBillingPeriod();
        $start = $period['start'];
        $end = $period['end'];

        $holidays = $this->getHolidayDates($start, $end);
        $holidayDates = collect(array_keys($holidays))->flip();
        $weekendDates = $this->getWeekendDates($start, $end);

        $presentDates = Attendance::where('user_id', $user->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', ['present', 'late'])
            ->get(['date'])
            ->map(fn ($attendance) => Carbon::parse($attendance->date)->format('Y-m-d'))
            ->unique()
            ->flip();

        $attendanceCount = $presentDates->count();
        $holidayCount = $holidayDates->merge($weekendDates)->unique()->count();

        $bonus = $attendanceCount >= 20 ? 200_000 : 0;
        $baseSalary = (int) ($user->salary_monthly ?? 0);
        $salary = $user->employment_status === 'probation'
            ? (int) floor($baseSalary / 2)
            : $baseSalary;

        return [
            'period_label' => $start->format('d M Y').' - '.$end->format('d M Y'),
            'attendance_count' => $attendanceCount,
            'holiday_count' => $holidayCount,
            'salary' => $salary,
            'bonus' => $bonus,
            'total' => $salary + $bonus,
        ];
    }

    public function buildSalarySlipData(User $user, User $superadmin, int $bonusExtra = 0): array
    {
        $summary = $this->buildBillingSummary($user);
        $companyName = $superadmin->company_name ?: config('app.name');
        $companyAddress = $superadmin->company_address;
        $extra = max(0, $bonusExtra);
        $bonusTotal = $summary['bonus'] + $extra;
        $total = $summary['salary'] + $bonusTotal;

        $company = Company::query()->find($user->company_id);
        $financeSignaturePath = $this->companyAssetDataUri($company, 'finance_signature_path', 'finance_signature_sha256');
        $hrdSignaturePath = $this->companyAssetDataUri($company, 'hrd_signature_path', 'hrd_signature_sha256');

        return [
            'user' => $user,
            'summary' => $summary,
            'companyName' => $companyName,
            'companyAddress' => $companyAddress,
            'financeSignaturePath' => $financeSignaturePath,
            'financeSignatureUrl' => $financeSignaturePath,
            'hrdSignaturePath' => $hrdSignaturePath,
            'hrdSignatureUrl' => $hrdSignaturePath,
            'hrdName' => 'Aji Sapta Pramulen',
            'financeName' => 'Patrika Setiyawan',
            'periodLabel' => $summary['period_label'],
            'salary' => $summary['salary'],
            'bonus' => $bonusTotal,
            'total' => $total,
            'attendanceCount' => $summary['attendance_count'],
            'holidayCount' => $summary['holiday_count'],
            'joinDate' => $user->join_date
                ? Carbon::parse($user->join_date)->format('d M Y')
                : '-',
        ];
    }

    private function companyAssetDataUri(?Company $company, string $pathField, string $checksumField): ?string
    {
        if (! $company || ! $company->{$pathField}) {
            return null;
        }

        return $this->privateAssets->dataUri(
            (int) $company->id,
            'company-signatures',
            (string) $company->id,
            $company->{$pathField},
            $company->{$checksumField},
        );
    }

    public function makeSalarySlipFileName(User $user): string
    {
        return 'slip-gaji-'.str_replace(' ', '-', strtolower($user->name)).'.pdf';
    }

    public function downloadSalarySlip(User $user, User $superadmin, int $bonusExtra = 0)
    {
        $slip = $this->buildSalarySlipData($user, $superadmin, $bonusExtra);

        Helpers::increasePdfMemoryLimit();

        $pdf = Pdf::loadView('admin.employees.salary-slip', $slip)
            ->setPaper('a4', 'portrait');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $this->makeSalarySlipFileName($user));
    }

    public function sendSalarySlipEmail(User $user, User $superadmin, int $bonusExtra = 0): void
    {
        $slip = $this->buildSalarySlipData($user, $superadmin, $bonusExtra);

        Mail::to($user->email)
            ->cc($superadmin->finance_email)
            ->send(new SalarySlipMail(
                $slip,
                $this->makeSalarySlipFileName($user),
                $user->name
            ));
    }

    public function getBillingPeriod(): array
    {
        $now = Carbon::now()->startOfDay();
        $periodEnd = $now->copy()->day(27);

        if ($now->day < 28) {
            $periodEnd = $now->copy()->subMonth()->day(27);
        }

        $periodStart = $periodEnd->copy()->subMonth()->day(28);

        return [
            'start' => $periodStart,
            'end' => $periodEnd,
        ];
    }

    public function getHolidayDates(Carbon $start, Carbon $end): array
    {
        $months = [];
        $cursor = $start->copy()->startOfMonth();
        $lastMonth = $end->copy()->startOfMonth();

        while ($cursor->lte($lastMonth)) {
            $months[] = [$cursor->year, $cursor->month];
            $cursor->addMonth();
        }

        $holidays = [];
        foreach ($months as [$year, $month]) {
            $cacheKey = "holidays-{$year}-{$month}";
            $data = Cache::remember($cacheKey, 60 * 60 * 6, function () use ($year, $month) {
                if (! config('services.holiday_api.enabled', true)) {
                    return [];
                }

                try {
                    $response = Http::timeout((int) config('services.holiday_api.timeout', 8))
                        ->get('https://api-harilibur.vercel.app/api', [
                            'month' => $month,
                            'year' => $year,
                        ]);
                } catch (\Throwable $e) {
                    report($e);

                    return [];
                }

                if (! $response->ok()) {
                    return [];
                }

                $payload = $response->json();
                if (! is_array($payload)) {
                    return [];
                }

                $result = [];
                foreach ($payload as $holiday) {
                    if (! empty($holiday['holiday_date']) && ! empty($holiday['holiday_name'])) {
                        $result[$holiday['holiday_date']] = [
                            'name' => $holiday['holiday_name'],
                            'is_national' => (bool) ($holiday['is_national_holiday'] ?? false),
                        ];
                    }
                }

                return $result;
            });

            $holidays = array_merge($holidays, $data);
        }

        $filtered = [];
        foreach ($holidays as $date => $info) {
            if ($date >= $start->toDateString() && $date <= $end->toDateString()) {
                $filtered[$date] = $info;
            }
        }

        return $filtered;
    }

    public function getWeekendDates(Carbon $start, Carbon $end): Collection
    {
        $dates = collect();
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            if ($cursor->isWeekend()) {
                $dates->push($cursor->format('Y-m-d'));
            }
            $cursor->addDay();
        }

        return $dates;
    }
}
