<?php

namespace App\Services;

use App\Helpers;
use App\Models\User;
use App\Models\WorkCertificate;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Surat Keterangan Kerja (Work Certificate) service.
 *
 * Fitur:
 *   - Karyawan aktif bisa self-issue (endpoint /me/work-certificate)
 *   - Admin bisa issue untuk karyawan lain (endpoint /admin/certificates/work)
 *   - Nomor surat unik per bulan: SKK/{YYYY}/{MM}/{SEQ}
 *   - Verification hash untuk QR code / URL verify publik
 *   - Blade template dengan kop surat + isi + ttd Direktur
 *
 * Karyawan resigned/terminated TIDAK bisa self-issue.
 */
class WorkCertificateService
{
    public function __construct(private readonly PrivateAssetStorage $privateAssets) {}

    /**
     * Generate cert untuk user + return record.
     *
     * Modes:
     *   - Active employee: teks "adalah benar karyawan aktif kami"
     *   - Former employee (resigned/terminated): teks "PERNAH bekerja pada
     *     periode X - Y" (past tense, sertakan tanggal masuk & tanggal resign)
     *
     * Karyawan aktif bisa self-issue (issuer=null diperbolehkan).
     * Former karyawan HANYA bisa di-issue oleh admin (issuer wajib).
     */
    public function issue(User $user, ?string $purpose = null, ?User $issuer = null): WorkCertificate
    {
        if ($user->is_inactive && ! $issuer) {
            throw new \RuntimeException('Karyawan sudah tidak aktif - hanya admin yang bisa terbitkan surat.');
        }
        if (! $user->join_date) {
            throw new \RuntimeException('Tanggal bergabung karyawan belum di-set. Hubungi HR.');
        }

        return DB::transaction(function () use ($user, $purpose, $issuer) {
            $now = now();
            $certNumber = $this->generateNumber($user->company_id, $now);
            $signerName = $user->company?->director_name ?? 'Aldy Iyik Be';

            return WorkCertificate::create([
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'cert_number' => $certNumber,
                'purpose' => $purpose,
                'issued_date' => $now->toDateString(),
                'issued_by' => $issuer?->id,
                'signer_name' => $signerName,
                'signer_title' => 'Direktur Utama',
                'verification_hash' => hash('sha256', $certNumber.'|'.$user->id.'|'.$now->timestamp.'|'.Str::random(16)),
            ]);
        });
    }

    /** Format nomor surat: SKK/{YYYY}/{MM}/{SEQ 3-digit}. Sequence reset per bulan. */
    private function generateNumber(int $companyId, Carbon $when): string
    {
        $year = $when->format('Y');
        $month = $when->format('m');
        $count = WorkCertificate::query()
            ->where('company_id', $companyId)
            ->whereYear('issued_date', $year)
            ->whereMonth('issued_date', (int) $month)
            ->count();
        $seq = str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);

        return "SKK/{$year}/{$month}/{$seq}";
    }

    /** Build data untuk blade template - resolve logo, ttd, tanggal Indonesia, dll. */
    public function buildTemplateData(WorkCertificate $cert): array
    {
        Helpers::increasePdfMemoryLimit();

        $user = $cert->user;
        $company = $cert->company;

        $signaturePath = $this->companyAssetDataUri($company, 'director_signature_path', 'director_signature_sha256');

        // Format tanggal Indonesia: "17 Juni 2026"
        $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $fmtDate = fn ($d) => $d ? ($d->day.' '.$bulan[$d->month - 1].' '.$d->year) : '-';

        $joinDate = $fmtDate($user->join_date);
        $issuedDate = $fmtDate($cert->issued_date);
        $resignedDate = $user->resigned_at || $user->last_working_date
            ? $fmtDate($user->last_working_date ?? $user->resigned_at)
            : null;

        // Karyawan aktif → "adalah benar karyawan aktif kami" (present tense)
        // Karyawan resigned/terminated → "PERNAH bekerja periode X-Y" (past tense)
        $isFormer = $user->is_inactive;

        return [
            'cert' => $cert,
            'user' => $user,
            'company' => $company,
            'jobTitle' => $user->jobTitle?->name ?? '-',
            'division' => $user->division?->name ?? '-',
            'joinDateFormatted' => $joinDate,
            'resignedDateFormatted' => $resignedDate,
            'issuedDateFormatted' => $issuedDate,
            'isFormer' => $isFormer,
            'letterheadStyle' => $company?->letterhead_style ?? 'modern',
            'signatureDataUri' => $signaturePath,
            // Stempel + logo - pakai cachedResizedImageDataUri yang bypass
            // pixel guard + cache resized version di disk. First call slow
            // (~1 detik untuk 13MP image), subsequent calls instant. Cache
            // auto-invalidate kalau file source diganti (via filemtime hash).
            //
            // Ukuran target dipilih sesuai display size di KOP (300px) dan
            // area TTD (200px). Hasilnya file 5-30KB PNG, sangat cepat di
            // embed dompdf → render < 5 detik total.
            // Stempel: constraint height only (pass width huge = 1000) supaya
            // rasio aspek asli terjaga. Kalau stempel persegi panjang (400×200)
            // gak dipaksa jadi persegi (yg bikin squished). ratio = min(1000/W, 150/H)
            // = 150/H → hanya height yang mengontrol.
            'stampDataUri' => $this->companyAssetDataUri($company, 'stamp_path', 'stamp_sha256'),
            'companyLogoDataUri' => Helpers::cachedResizedImageDataUri($company?->logo, 300, 300),
            'verifyUrl' => rtrim(config('app.url'), '/').'/verify/skk/'.$cert->verification_hash,
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

    public function downloadResponse(WorkCertificate $cert): StreamedResponse
    {
        $data = $this->buildTemplateData($cert);
        $pdf = Pdf::loadView('admin.certificates.work', $data)->setPaper('a4', 'portrait');

        $filename = 'surat-keterangan-kerja-'
            .str_replace(' ', '-', strtolower($cert->user->name))
            .'-'.$cert->issued_date->format('Y-m-d').'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
