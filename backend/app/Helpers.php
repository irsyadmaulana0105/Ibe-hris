<?php

namespace App;

use Illuminate\Support\Facades\Storage;

class Helpers
{
    public static function getGoogleMapsUrl($lat, $lng)
    {
        return "https://maps.google.com/maps?q=$lat,$lng";
    }

    public static function getFrontendUrl(): string
    {
        $configuredUrls = preg_split('/\s+/', trim((string) config('app.frontend_url', ''))) ?: [];
        $frontendUrl = collect($configuredUrls)->filter()->first();

        return is_string($frontendUrl) && $frontendUrl !== ''
            ? $frontendUrl
            : 'https://attendance.ibereality.com';
    }

    /**
     * Get the URL path from the app URL
     *
     * E.g. base url/app url = http://localhost:8000/path => path
     *
     * Returns empty string if base url is root path
     */
    public static function getNonRootBaseUrlPath()
    {
        $segments = explode('/', parse_url(config('app.url'), PHP_URL_PATH));
        return count($segments) < 2 ? '' : $segments[1];
    }

    public static function mailConfigurationIssue(): ?string
    {
        $mailer = (string) config('mail.default', '');

        if (in_array($mailer, ['log', 'array'], true)) {
            return "MAIL_MAILER masih `{$mailer}` sehingga email tidak benar-benar dikirim.";
        }

        $fromAddress = (string) config('mail.from.address', '');
        if ($fromAddress === '' || $fromAddress === 'hello@example.com') {
            return 'MAIL_FROM_ADDRESS belum diatur dengan alamat email pengirim yang valid.';
        }

        if ($mailer === 'smtp') {
            $host = (string) config('mail.mailers.smtp.host', '');
            $port = (string) config('mail.mailers.smtp.port', '');

            if ($host === '' || $port === '') {
                return 'Konfigurasi SMTP belum lengkap. Pastikan MAIL_HOST dan MAIL_PORT sudah diisi.';
            }
        }

        return null;
    }

    public static function increasePdfMemoryLimit(): void
    {
        $limit = (string) env('PDF_MEMORY_LIMIT', '256M');
        if ($limit !== '') {
            @ini_set('memory_limit', $limit);
        }
    }

    /**
     * Perkecil gambar yang baru diunggah, di tempat, kalau sisi terpanjangnya
     * melebihi $maxSide. Alpha dipertahankan.
     *
     * Dipakai untuk logo perusahaan dan logo kop surat. Logo 4096x3282 pernah
     * ditanam utuh ke kwitansi/kontrak PDF; Imagick di dalam DomPDF lalu
     * memenuhi /tmp container (128 MB) dan sejak itu SEMUA unggahan berkas di
     * ERP gagal (28 Sep & 1 Okt 2026). Logo dicetak paling lebar ~50 mm, jadi
     * 1200 px sudah jauh di atas kebutuhan.
     *
     * Gagal diam-diam (file asli dibiarkan) - lebih baik logo besar daripada
     * unggahan pengguna ditolak.
     */
    public static function shrinkStoredImage(string $disk, string $path, int $maxSide = 1200): void
    {
        $abs = Storage::disk($disk)->path($path);
        $info = @getimagesize($abs);
        if (! $info || max($info[0], $info[1]) <= $maxSide) {
            return;
        }
        [$w, $h] = $info;
        self::increasePdfMemoryLimit();

        $src = match ($info['mime']) {
            'image/png' => @imagecreatefrompng($abs),
            'image/jpeg' => @imagecreatefromjpeg($abs),
            'image/webp' => @imagecreatefromwebp($abs),
            default => null,
        };
        if (! $src) {
            return;
        }

        $ratio = $maxSide / max($w, $h);
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        // Tulis ke file sementara lalu rename, supaya file asli tidak pernah
        // setengah tertulis kalau proses terhenti.
        $tmp = $abs.'.resize';
        $ok = match ($info['mime']) {
            'image/jpeg' => imagejpeg($dst, $tmp, 88),
            'image/webp' => imagewebp($dst, $tmp, 90),
            default => imagepng($dst, $tmp, 6),
        };
        imagedestroy($src);
        imagedestroy($dst);

        if ($ok && @getimagesize($tmp)) {
            @rename($tmp, $abs);
        } else {
            @unlink($tmp);
        }
    }

    /**
     * Resize + encode image ke data URI dengan file cache.
     *
     * Bypass pixel guard yang bikin resolveStorageImageDataUri fallback ke raw
     * (yang bikin dompdf lambat karena embed image super besar).
     * First call: decode → resize → save cache → return.
     * Subsequent calls: read cache → return (fast).
     * Cache invalidated pakai filemtime source - kalau file diganti, hash baru.
     *
     * Pakai untuk logo perusahaan / stempel di KOP surat yang butuh render cepat.
     *
     * Return null kalau image tidak bisa di-load (bukan format supported / corrupt).
     */
    public static function cachedResizedImageDataUri(?string $storagePath, int $maxWidth = 300, int $maxHeight = 300): ?string
    {
        if (!$storagePath) return null;
        $absPath = \Illuminate\Support\Facades\Storage::disk('public')->path($storagePath);
        if (!is_file($absPath) || !is_readable($absPath)) return null;

        // Cache key: source path + mtime + target size
        $sig = md5($absPath . '|' . filemtime($absPath) . '|' . $maxWidth . 'x' . $maxHeight);
        $cacheDir = storage_path('app/image-cache');
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $sig . '.png';

        // Hit cache - instant
        if (is_file($cacheFile)) {
            $bytes = @file_get_contents($cacheFile);
            return $bytes ? 'data:image/png;base64,' . base64_encode($bytes) : null;
        }

        // Miss - resize + save cache
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        self::increasePdfMemoryLimit();

        $info = @getimagesize($absPath);
        if (!$info) return null;
        [$srcW, $srcH] = $info;
        $mime = $info['mime'];

        $src = match ($mime) {
            'image/png'  => @imagecreatefrompng($absPath),
            'image/jpeg' => @imagecreatefromjpeg($absPath),
            'image/webp' => @imagecreatefromwebp($absPath),
            'image/gif'  => @imagecreatefromgif($absPath),
            default      => null,
        };
        if (!$src) return null;

        // Preserve aspect ratio, scale to fit within target
        $ratio = min($maxWidth / $srcW, $maxHeight / $srcH, 1.0);
        $dstW  = max(1, (int) round($srcW * $ratio));
        $dstH  = max(1, (int) round($srcH * $ratio));

        $dst = imagecreatetruecolor($dstW, $dstH);
        // Preserve alpha untuk PNG/GIF/WebP (background transparan)
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagepng($dst, $cacheFile, 6);
        imagedestroy($src);
        imagedestroy($dst);

        $bytes = @file_get_contents($cacheFile);
        return $bytes ? 'data:image/png;base64,' . base64_encode($bytes) : null;
    }

    /**
     * @param array<int, mixed> $emails
     * @param array<int, mixed> $exclude
     * @return array<int, string>
     */
    public static function sanitizeEmailList(array $emails, array $exclude = []): array
    {
        $excluded = collect($exclude)
            ->filter(fn ($email) => is_string($email))
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->map(fn (string $email) => strtolower($email))
            ->values()
            ->all();

        return collect($emails)
            ->filter(fn ($email) => is_string($email))
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->reject(fn (string $email) => in_array(strtolower($email), $excluded, true))
            ->unique(fn (string $email) => strtolower($email))
            ->values()
            ->all();
    }

    public static function resolveStorageImageDataUri(?string $path, int $maxWidth = 320, int $maxHeight = 120): ?string
    {
        if (!$path) {
            return null;
        }

        return self::optimizedImageDataUriFromAbsolutePath(
            Storage::disk('public')->path($path)
            , $maxWidth
            , $maxHeight
        );
    }

    public static function imageDataUriFromAbsolutePath(?string $absolutePath): ?string
    {
        if (!$absolutePath || !is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $mimeType = self::detectImageMimeType($absolutePath);
        if (!str_starts_with($mimeType, 'image/')) {
            return null;
        }

        $contents = @file_get_contents($absolutePath);
        if ($contents === false) {
            return null;
        }

        return 'data:' . $mimeType . ';base64,' . base64_encode($contents);
    }

    public static function optimizedImageDataUriFromAbsolutePath(?string $absolutePath, int $maxWidth = 320, int $maxHeight = 120): ?string
    {
        self::increasePdfMemoryLimit();

        if (!$absolutePath || !is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $mimeType = self::detectImageMimeType($absolutePath);
        if ($mimeType === 'image/svg+xml') {
            return self::imageDataUriFromAbsolutePath($absolutePath);
        }

        if (!function_exists('getimagesize')) {
            return self::imageDataUriFromAbsolutePath($absolutePath);
        }

        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
            return self::imageDataUriFromAbsolutePath($absolutePath);
        }

        $maxBytes = (int) env('PDF_IMAGE_MAX_BYTES', 3 * 1024 * 1024);
        $fileSize = @filesize($absolutePath);
        if ($maxBytes > 0 && is_int($fileSize) && $fileSize > $maxBytes) {
            return null;
        }

        $size = @getimagesize($absolutePath);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            return self::imageDataUriFromAbsolutePath($absolutePath);
        }

        [$width, $height] = $size;
        $maxPixels = (int) env('PDF_IMAGE_MAX_PIXELS', 6_000_000);
        if ($maxPixels > 0 && ($width * $height) > $maxPixels) {
            return null;
        }

        $sourceImage = self::createImageResource($absolutePath, $mimeType);
        if (!$sourceImage) {
            return null;
        }

        $scale = min(
            1,
            $maxWidth > 0 ? ($maxWidth / $width) : 1,
            $maxHeight > 0 ? ($maxHeight / $height) : 1
        );

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$targetImage) {
            imagedestroy($sourceImage);
            return self::imageDataUriFromAbsolutePath($absolutePath);
        }

        $preserveAlpha = in_array($mimeType, ['image/png', 'image/gif', 'image/webp'], true);
        if ($preserveAlpha) {
            imagealphablending($targetImage, false);
            imagesavealpha($targetImage, true);
            $transparent = imagecolorallocatealpha($targetImage, 255, 255, 255, 127);
            imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        ob_start();
        $outputMimeType = match ($mimeType) {
            'image/jpeg' => imagejpeg($targetImage, null, 82) ? 'image/jpeg' : null,
            default => imagepng($targetImage, null, 6) ? 'image/png' : null,
        };
        $contents = ob_get_clean();

        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        if (!is_string($contents) || $contents === '' || !$outputMimeType) {
            return null;
        }

        return 'data:' . $outputMimeType . ';base64,' . base64_encode($contents);
    }

    private static function createImageResource(string $path, string $mimeType): mixed
    {
        return match ($mimeType) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            'image/gif' => function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private static function detectImageMimeType(string $path): string
    {
        $detected = function_exists('mime_content_type') ? @mime_content_type($path) : false;
        if (is_string($detected) && str_starts_with($detected, 'image/')) {
            return $detected;
        }

        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }
}
