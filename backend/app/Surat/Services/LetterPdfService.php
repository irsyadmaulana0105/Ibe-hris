<?php

namespace App\Surat\Services;

use App\Surat\Models\Letter;
use App\Surat\Models\LetterSetting;
use App\Surat\Models\LetterSignature;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Render surat ke PDF via dompdf.
 *
 * Pakai universal Blade template `surat.render-pdf` yang load KOP header,
 * body_html (sudah ter-substitusi placeholder), dan signature block.
 *
 * Output disimpan di storage/letters/{YYYY}/{seq}-{slug}.pdf
 */
class LetterPdfService
{
    public function __construct(private LetterTemplateService $tplSvc) {}

    /** Render + return PDF binary string. Untuk preview. */
    public function renderToString(Letter $letter): string
    {
        return $this->buildPdf($letter)->output();
    }

    /** Render + save ke storage. Return: relative path dari storage/app/. */
    public function renderToFile(Letter $letter): string
    {
        $year = $letter->letter_date
            ? Carbon::parse($letter->letter_date)->format('Y')
            : date('Y');
        $seq = $letter->letter_number_seq ?? 'draft';
        $slug = $this->slugify($letter->subject ?? 'surat');
        $name = sprintf('%s-%s.pdf', str_pad((string) $seq, 3, '0', STR_PAD_LEFT), $slug);
        $rel = "letters/{$year}/{$name}";

        Storage::makeDirectory(dirname($rel));
        Storage::put($rel, $this->buildPdf($letter)->output());

        return $rel;
    }

    private function buildPdf(Letter $letter)
    {
        $renderedBody = $this->tplSvc->renderBody($letter->body_html ?? '', $letter);

        $signer = $letter->signer;
        $cosigner = $letter->cosigner;
        $signerSignatureDataUri = $this->privateSignatureAssetDataUri($signer, 'signature_path');
        $signerStampDataUri = $this->privateSignatureAssetDataUri($signer, 'stamp_path');
        $cosignerSignatureDataUri = $this->privateSignatureAssetDataUri($cosigner, 'signature_path');
        $cosignerStampDataUri = $this->privateSignatureAssetDataUri($cosigner, 'stamp_path');

        // Load settings dari DB - user-editable di /settings page.
        $s = LetterSetting::forCompany($letter->company_id);

        $logoUrl = null;
        if ($s->logo_path) {
            $abs = storage_path('app/public/'.$s->logo_path);
            if (is_file($abs)) {
                $logoUrl = $abs;
            }
        }

        return Pdf::loadView('surat.render-pdf', [
            'letter' => $letter,
            'renderedBody' => $renderedBody,
            'signer' => $signer,
            'cosigner' => $cosigner,
            'signer_signature_data_uri' => $signerSignatureDataUri,
            'signer_stamp_data_uri' => $signerStampDataUri,
            'cosigner_signature_data_uri' => $cosignerSignatureDataUri,
            'cosigner_stamp_data_uri' => $cosignerStampDataUri,
            'kop_logo_url' => $logoUrl,
            'kop_company' => $s->company_name ?? 'PT INTEGRASI BISNIS EKSEKUTIF',
            'kop_brand' => $s->brand_name ?? 'IBE REALITY',
            'kop_tagline' => $s->tagline ?? 'Software · System Integration · Digital Solutions',
            'kop_address' => $s->address ?? 'Sidoarjo, Jawa Timur, Indonesia',
            'kop_phone' => $s->phone ?? '+62 813-3005-8541',
            'kop_email' => $s->email ?? 'admin@ibereality.com',
            'kop_website' => $s->website ?? 'www.ibereality.com',
            'kop_color' => $s->kop_color ?? '#0d9488',
            'kop_company_full' => 'PT Integrasi Bisnis Eksekutif',
            'kop_confidential' => $letter->confidential,
        ])->setPaper('a4', 'portrait');
    }

    private function privateSignatureAssetDataUri(?LetterSignature $signature, string $field): ?string
    {
        if (! $signature || ! filled($signature->{$field})) {
            return null;
        }

        $relativePath = ltrim((string) $signature->{$field}, '/');
        $expectedPrefix = 'private/letters/signatures/'.(int) $signature->company_id.'/';
        if (! str_starts_with($relativePath, $expectedPrefix)
            || preg_match('#(?:\.\.|\\\\|\x00)#', $relativePath)) {
            throw new \RuntimeException('Lokasi aset tanda tangan bukan penyimpanan privat tenant.');
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($relativePath)) {
            throw new \RuntimeException('Aset tanda tangan privat tidak ditemukan.');
        }

        $bytes = $disk->get($relativePath);
        if ($bytes === '' || strlen($bytes) > 2 * 1024 * 1024) {
            throw new \RuntimeException('Ukuran aset tanda tangan privat tidak valid.');
        }

        $imageInfo = @getimagesizefromstring($bytes);
        $mime = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;
        $width = is_array($imageInfo) ? (int) ($imageInfo[0] ?? 0) : 0;
        $height = is_array($imageInfo) ? (int) ($imageInfo[1] ?? 0) : 0;
        if ($mime !== 'image/png') {
            throw new \RuntimeException('Format aset tanda tangan privat tidak valid.');
        }
        if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096
            || $width * $height > 16_000_000) {
            throw new \RuntimeException('Dimensi aset tanda tangan privat tidak valid.');
        }
        if (! preg_match('/-([a-f0-9]{64})\.png$/', $relativePath, $checksumMatch)
            || ! hash_equals($checksumMatch[1], hash('sha256', $bytes))) {
            throw new \RuntimeException('Integritas aset tanda tangan privat tidak valid.');
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function slugify(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);

        return trim($s, '-') ?: 'surat';
    }
}
