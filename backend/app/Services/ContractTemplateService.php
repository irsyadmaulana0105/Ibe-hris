<?php

namespace App\Services;

use App\Helpers;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\User;
use App\Surat\Services\HtmlSanitizerService;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contract Template - variable substitution engine + preview + generate.
 *
 * Support variables (semua opsional, di-substitute kalau ada di body):
 *   Employee: nama, nip, ktp_number, npwp, jabatan, divisi, tanggal_lahir,
 *             tempat_lahir, alamat, phone, email
 *   Employment: employment_status, tanggal_mulai_kerja, gaji_pokok, gaji_perhari,
 *               daily_rate, jam_kerja
 *   Company: nama_perusahaan, alamat_perusahaan, telpon_perusahaan, email_perusahaan,
 *            website_perusahaan, npwp_perusahaan, nib_perusahaan, direktur_utama
 *   Contract: nomor_kontrak, tanggal_kontrak, tanggal_akhir, durasi_bulan
 *   Payroll: uang_makan, tunjangan_transport, tunjangan_kehadiran
 */
class ContractTemplateService
{
    public function __construct(
        private readonly PrivateAssetStorage $privateAssets,
        private readonly HtmlSanitizerService $htmlSanitizer,
    ) {}

    public function sanitizeBody(string $bodyHtml): string
    {
        return $this->htmlSanitizer->cleanContractTemplate($bodyHtml);
    }

    /** Get template untuk employment_type, atau null kalau belum ada. */
    public function getForType(int $companyId, string $employmentType): ?ContractTemplate
    {
        return ContractTemplate::where('company_id', $companyId)
            ->where('employment_type', $employmentType)
            ->where('is_active', true)
            ->first();
    }

    /** Build variable map dari user + company + payload optional. */
    public function buildVariables(User $user, Company $company, array $extras = []): array
    {
        $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $fmtDate = fn ($d) => $d ? ($d->day.' '.$bulan[$d->month - 1].' '.$d->year) : '-';

        $today = now();

        return array_merge([
            // Employee
            'nama' => $user->name,
            'nip' => $user->nip ?? '-',
            'ktp_number' => $user->ktp_number ?? '-',
            'npwp' => $user->npwp ?? '-',
            'jabatan' => $user->jobTitle?->name ?? '-',
            'divisi' => $user->division?->name ?? '-',
            'tanggal_lahir' => $fmtDate($user->birth_date),
            'tempat_lahir' => $user->birth_place ?? '-',
            'alamat' => $user->address ?? '-',
            'phone' => $user->phone ?? '-',
            'email' => $user->email ?? '-',

            // Employment
            'employment_status' => User::EMPLOYMENT_STATUSES[$user->employment_status] ?? $user->employment_status,
            'tanggal_mulai_kerja' => $fmtDate($user->join_date),
            'gaji_pokok' => number_format($user->salary_monthly ?? 0, 0, ',', '.'),
            'gaji_perhari' => number_format($user->daily_rate ?? 0, 0, ',', '.'),
            'daily_rate' => number_format($user->daily_rate ?? 0, 0, ',', '.'),
            'training_mulai' => $fmtDate($user->training_started_at),
            'training_akhir' => $fmtDate($user->training_ends_at),
            'uang_makan' => number_format($user->meal_transport_rate ?? 0, 0, ',', '.'),
            'tunjangan_kehadiran' => number_format($user->attendance_allowance ?? 0, 0, ',', '.'),

            // Company
            'nama_perusahaan' => $company->name ?? '-',
            'alamat_perusahaan' => $this->buildAddress($company),
            'telpon_perusahaan' => $company->phone ?? '-',
            'email_perusahaan' => $company->email ?? '-',
            'website_perusahaan' => $company->website ?? '-',
            'npwp_perusahaan' => $company->npwp ?? '-',
            'nib_perusahaan' => $company->nib ?? '-',
            'direktur_utama' => $company->director_name ?? 'Aldy Iyik Be',

            // Date now
            'tanggal_sekarang' => $fmtDate($today),
            'tahun_sekarang' => $today->year,
        ], $extras);
    }

    private function buildAddress(?Company $c): string
    {
        if (! $c) {
            return '-';
        }
        $parts = array_filter([
            $c->address,
            trim(implode(', ', array_filter([$c->district, $c->regency, $c->province, $c->postal_code]))),
        ]);

        return implode(', ', $parts);
    }

    /**
     * Render template body HTML dengan variables di-substitute.
     * `{{nama}}` → nilai actual. Missing var → di-biarkan as-is (mempermudah debug).
     */
    public function render(string $bodyHtml, array $variables): string
    {
        $bodyHtml = $this->sanitizeBody($bodyHtml);

        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            fn ($m) => isset($variables[$m[1]]) ? htmlspecialchars((string) $variables[$m[1]], ENT_QUOTES) : $m[0],
            $bodyHtml,
        );
    }

    /** Preview dengan sample data - dipakai admin untuk lihat hasil sebelum activate. */
    public function previewSample(ContractTemplate $tpl): string
    {
        [$sampleUser, $company] = $this->buildSampleContext($tpl);
        $vars = $this->buildVariables($sampleUser, $company);

        return $this->render($tpl->body_html, $vars);
    }

    /**
     * Generate PDF preview (full letter format dengan KOP surat + signature block).
     * Return sebagai StreamedResponse inline (bisa di-embed iframe di frontend).
     */
    public function generatePreviewPdf(ContractTemplate $tpl): StreamedResponse
    {
        [$sampleUser, $company] = $this->buildSampleContext($tpl);
        $vars = $this->buildVariables($sampleUser, $company);
        $renderedHtml = $this->render($tpl->body_html, $vars);

        $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $now = now();
        $placeDate = ($company?->regency ?? 'Malang').', '
            .$now->day.' '.$bulan[$now->month - 1].' '.$now->year;

        $data = [
            'tplTitle' => $tpl->title,
            'renderedHtml' => $renderedHtml,
            'company' => $company,
            'employeeName' => $sampleUser->name,
            'signerName' => $company?->director_name ?? 'Aldy Iyik Be',
            'signerTitle' => 'Direktur Utama',
            'placeDate' => $placeDate,
            'isPreview' => true,
            'letterheadStyle' => $company?->letterhead_style ?? 'modern',
            'companyLogoDataUri' => Helpers::cachedResizedImageDataUri($company?->logo, 300, 300),
            'stampDataUri' => $this->companyAssetDataUri($company, 'stamp_path', 'stamp_sha256'),
            'signatureDataUri' => $this->companyAssetDataUri($company, 'director_signature_path', 'director_signature_sha256'),
            'employeeSignatureDataUri' => null,
            'hrdSignatureDataUri' => null,
        ];

        $pdf = Pdf::loadView('admin.contracts.template', $data)->setPaper('a4', 'portrait');

        $filename = 'preview-kontrak-'.$tpl->employment_type.'-'.now()->format('Y-m-d-His').'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ],
        );
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

    /** Sample user + company untuk preview - 1 karyawan real atau dummy. */
    private function buildSampleContext(ContractTemplate $tpl): array
    {
        // Preview PAKAI DUMMY user - jangan pernah pakai data karyawan real supaya
        // (a) HRD tidak salah kira template = kontrak Ardi/Syawal spesifik
        // (b) tidak leak data karyawan lain ke preview yang mungkin di-share
        $sampleUser = new User;
        $sampleUser->name = 'CONTOH KARYAWAN';
        $sampleUser->nip = 'IBE-XXXX-XXX';
        $sampleUser->ktp_number = '35XXXXXXXXXXXXXX';
        $sampleUser->address = '[Alamat karyawan akan diisi otomatis]';
        $sampleUser->phone = '08XX-XXXX-XXXX';
        $sampleUser->email = 'nama.karyawan@example.com';
        $sampleUser->salary_monthly = 5_000_000;
        $sampleUser->daily_rate = 125_000;
        $sampleUser->birth_date = now()->subYears(25);
        $sampleUser->birth_place = '[Tempat lahir]';
        $sampleUser->join_date = now();

        $company = Company::find($tpl->company_id);

        return [$sampleUser, $company];
    }
}
