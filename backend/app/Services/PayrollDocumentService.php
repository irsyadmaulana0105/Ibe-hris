<?php

namespace App\Services;

use App\Helpers;
use App\Mail\PayrollSlipMail;
use App\Models\Payroll;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollDocumentService
{
    public function __construct(private readonly PrivateAssetStorage $privateAssets) {}

    public function findForEmployee(int $payrollId, string|int|null $userId): Payroll
    {
        // Karyawan hanya boleh lihat payroll yang sudah 'paid' DAN bukti transfer sudah diupload.
        return Payroll::withoutGlobalScopes()
            ->where('id', $payrollId)
            ->where('user_id', $userId)
            ->where('status', 'paid')
            ->whereNotNull('transfer_proof_path')
            ->with(['user.division', 'user.jobTitle', 'user.company'])
            ->firstOrFail();
    }

    public function findForCompany(int $payrollId, int|string|null $companyId): Payroll
    {
        return Payroll::withoutGlobalScopes()
            ->where('id', $payrollId)
            ->where('company_id', $companyId)
            ->with(['user.division', 'user.jobTitle', 'user.company'])
            ->findOrFail($payrollId);
    }

    public function buildSlipData(Payroll $payroll): array
    {
        Helpers::increasePdfMemoryLimit();

        $company = $payroll->user->company;
        $superadmin = User::where('group', 'superadmin')
            ->where('company_id', $company?->id)
            ->first();

        // Re-evaluate custom formula components (non-default) supaya tampil sebagai
        // baris terpisah di slip - bukan hanya hilang dalam gross_total saja.
        $evalContext = [
            'basic_salary' => (int) $payroll->base_salary,
            'working_days' => (int) $payroll->attendance_count,
            'overtime_hours' => (int) $payroll->overtime_count,
            'meal_transport' => (int) $payroll->meal_transport_total,
            'thr' => (int) $payroll->thr_total,
            'bonus' => (int) $payroll->bonus_total,
            'reimbursement' => (int) $payroll->reimbursement_total,
            'gross' => (int) $payroll->gross_total,
            'taxable_income' => max(0, (int) $payroll->gross_total * 12 - 54_000_000),
        ];
        $customEarnings = app(PayrollFormulaEvaluator::class)
            ->evaluateCategory($payroll->company_id, 'earning', $evalContext);
        $customDeductions = app(PayrollFormulaEvaluator::class)
            ->evaluateCategory($payroll->company_id, 'deduction', $evalContext);

        return [
            'payroll' => $payroll,
            'user' => $payroll->user,
            'company' => $company,
            'companyName' => $company?->name ?? config('app.name'),
            'companyAddress' => $company?->address,
            'companyLogoDataUri' => $this->resolvePayrollLogoDataUri($company?->logo, 220, 90),
            // Variable name match dengan slip.blade.php (gunakan *Path, bukan *DataUri)
            'financeSignaturePath' => $this->companyAssetDataUri($company, 'finance_signature_path', 'finance_signature_sha256'),
            'hrdSignaturePath' => $this->companyAssetDataUri($company, 'hrd_signature_path', 'hrd_signature_sha256'),
            'hrdName' => 'Direktur HRD',
            'financeName' => 'Direktur Keuangan',
            'periodLabel' => $payroll->period_label,
            'joinDate' => $payroll->user->join_date
                ? Carbon::parse($payroll->user->join_date)->format('d M Y')
                : '-',
            // Custom components yang user definisikan via Settings → Payroll Formula
            'customEarnings' => $customEarnings['components'] ?? [],
            'customDeductions' => $customDeductions['components'] ?? [],
        ];
    }

    private function companyAssetDataUri($company, string $pathField, string $checksumField): ?string
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

    public function makeSlipFileName(Payroll $payroll): string
    {
        return 'slip-gaji-'
            .str_replace(' ', '-', strtolower($payroll->user->name))
            .'-'.$payroll->year.'-'.str_pad($payroll->month, 2, '0', STR_PAD_LEFT)
            .'.pdf';
    }

    public function downloadResponse(Payroll $payroll): StreamedResponse
    {
        $slipData = $this->buildSlipData($payroll);

        $pdf = Pdf::loadView('admin.payroll.slip', $slipData)
            ->setPaper('a4', 'portrait');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $this->makeSlipFileName($payroll),
            ['Content-Type' => 'application/pdf']
        );
    }

    public function sendSlipEmail(Payroll $payroll, string $recipientEmail, array $bccEmails = []): void
    {
        $slipData = $this->buildSlipData($payroll);
        $bccEmails = Helpers::sanitizeEmailList($bccEmails, [$recipientEmail]);

        $mailer = Mail::to($recipientEmail);
        if ($bccEmails !== []) {
            $mailer->bcc($bccEmails);
        }

        $mailer->send(new PayrollSlipMail(
            $slipData,
            $this->makeSlipFileName($payroll),
            $payroll->user->name
        ));
    }

    public function internalRecipientsForPayroll(Payroll $payroll): array
    {
        $ccEmail = $payroll->user->company?->finance_email
            ?: User::where('group', 'superadmin')
                ->where('company_id', $payroll->user->company_id)
                ->value('finance_email');

        return Helpers::sanitizeEmailList([
            $ccEmail,
            Auth::user()?->email,
        ], [$payroll->user->email]);
    }

    private function resolvePayrollLogoDataUri(?string $companyLogoPath, int $maxWidth = 220, int $maxHeight = 90): ?string
    {
        $candidates = [];

        if ($companyLogoPath) {
            $candidates[] = public_path('storage/'.ltrim($companyLogoPath, '/'));
        }

        $candidates[] = public_path('asset/images/logo.png');
        $candidates[] = public_path('images/logo.png');

        foreach ($candidates as $candidate) {
            $dataUri = Helpers::optimizedImageDataUriFromAbsolutePath($candidate, $maxWidth, $maxHeight);
            if ($dataUri) {
                return $dataUri;
            }
        }

        return null;
    }
}
