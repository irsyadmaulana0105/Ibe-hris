<?php

namespace App\Services;

use App\Exceptions\PrivateAssetIntegrityException;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Tenant-scoped private asset storage with content validation and integrity checks.
 *
 * Stored paths are opaque implementation details. Callers must persist both path
 * and sha256, then expose bytes only through an authenticated controller.
 */
class PrivateAssetStorage
{
    public const MAX_PNG_BYTES = 2 * 1024 * 1024;

    public const MAX_PDF_BYTES = 10 * 1024 * 1024;

    public const MAX_IMAGE_DIMENSION = 4096;

    public const MAX_IMAGE_PIXELS = 16_000_000;

    /** @return array{path:string,sha256:string,mime:string,size:int,width:int,height:int} */
    public function storeUploadedPng(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        UploadedFile $file,
    ): array {
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw new PrivateAssetIntegrityException('unreadable', 'Uploaded image could not be read.', 422);
        }

        return $this->storePng($tenantId, $category, $resourceKey, $field, $bytes);
    }

    /** @return array{path:string,sha256:string,mime:string,size:int,width:int,height:int} */
    public function storePng(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        string $bytes,
    ): array {
        $metadata = $this->validatePng($bytes);
        $path = $this->newPath($tenantId, $category, $resourceKey, $field, 'png');

        return $this->writeVerified($path, $bytes, $metadata);
    }

    /** @return array{path:string,sha256:string,mime:string,size:int} */
    public function storeUploadedPdf(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        UploadedFile $file,
    ): array {
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw new PrivateAssetIntegrityException('unreadable', 'Uploaded PDF could not be read.', 422);
        }

        return $this->storePdf($tenantId, $category, $resourceKey, $field, $bytes);
    }

    /** @return array{path:string,sha256:string,mime:string,size:int} */
    public function storePdf(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        string $bytes,
    ): array {
        $metadata = $this->validatePdf($bytes);
        $path = $this->newPath($tenantId, $category, $resourceKey, $field, 'pdf');

        return $this->writeVerified($path, $bytes, $metadata);
    }

    /** @return array{path:string,sha256:string,mime:string,size:int,width?:int,height?:int} */
    public function copyLegacyToPrivate(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        string $legacyPath,
        string $kind,
        array $allowedLegacyPrefixes,
    ): array {
        $this->assertLegacyPath($legacyPath, $allowedLegacyPrefixes);
        if (! Storage::disk('public')->exists($legacyPath)) {
            throw new PrivateAssetIntegrityException('missing', 'Referenced public asset is missing.', 410);
        }

        $bytes = Storage::disk('public')->get($legacyPath);

        return $kind === 'png'
            ? $this->storePng($tenantId, $category, $resourceKey, $field, $bytes)
            : $this->storePdf($tenantId, $category, $resourceKey, $field, $bytes);
    }

    public function strictDecodePngDataUri(string $dataUri): string
    {
        if (! preg_match('/\Adata:image\/png;base64,([A-Za-z0-9+\/=]+)\z/D', $dataUri, $matches)) {
            throw new PrivateAssetIntegrityException('invalid_encoding', 'Signature must be a strict PNG data URI.', 422);
        }

        $bytes = base64_decode($matches[1], true);
        if ($bytes === false) {
            throw new PrivateAssetIntegrityException('invalid_encoding', 'Signature base64 is invalid.', 422);
        }

        $this->validatePng($bytes);

        return $bytes;
    }

    public function readVerified(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $path,
        ?string $expectedSha256,
        string $kind,
    ): string {
        $this->assertPrivatePath($tenantId, $category, $resourceKey, $path);
        $this->assertChecksum($expectedSha256);

        if (! Storage::disk('local')->exists($path)) {
            throw new PrivateAssetIntegrityException('missing', 'Private asset is missing.', 410);
        }

        $bytes = Storage::disk('local')->get($path);
        $actual = hash('sha256', $bytes);
        if (! hash_equals(strtolower($expectedSha256), $actual)) {
            throw new PrivateAssetIntegrityException('tampered', 'Private asset integrity verification failed.', 409);
        }

        $kind === 'png' ? $this->validatePng($bytes) : $this->validatePdf($bytes);

        return $bytes;
    }

    public function dataUri(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        ?string $path,
        ?string $expectedSha256,
        string $kind = 'png',
    ): ?string {
        if ($path === null || $path === '') {
            return null;
        }

        $bytes = $this->readVerified(
            $tenantId,
            $category,
            $resourceKey,
            $path,
            $expectedSha256,
            $kind,
        );

        $mime = $kind === 'png' ? 'image/png' : 'application/pdf';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    public function downloadResponse(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $path,
        ?string $expectedSha256,
        string $kind,
        string $downloadName,
        bool $inline = true,
    ): Response {
        $bytes = $this->readVerified(
            $tenantId,
            $category,
            $resourceKey,
            $path,
            $expectedSha256,
            $kind,
        );
        $sha256 = hash('sha256', $bytes);
        $mime = $kind === 'png' ? 'image/png' : 'application/pdf';
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '-', $downloadName) ?: 'asset';

        return response($bytes, 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$safeName.'"',
            'Content-Length' => (string) strlen($bytes),
            'Content-Type' => $mime,
            'Digest' => 'sha-256='.base64_encode(hex2bin($sha256)),
            'ETag' => '"'.$sha256.'"',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Build a short-lived same-origin URL after the caller has authorized the
     * entity. Parameters must be fixed identifiers/enums, never a storage path.
     */
    public function temporarySignedRoute(string $route, array $identifiers, int $seconds = 300): string
    {
        $seconds = max(1, min(300, $seconds));
        foreach ($identifiers as $key => $value) {
            if (str_contains((string) $key, 'path') || is_array($value) || is_object($value)) {
                throw new PrivateAssetIntegrityException('invalid_route_identifier', 'Signed asset URL identifiers are invalid.', 409);
            }
        }

        return URL::temporarySignedRoute($route, now()->addSeconds($seconds), $identifiers);
    }

    public function deletePrivate(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        ?string $path,
    ): void {
        if ($path === null || $path === '') {
            return;
        }

        $this->assertPrivatePath($tenantId, $category, $resourceKey, $path);
        Storage::disk('local')->delete($path);
    }

    public function isPrivatePathFor(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        ?string $path,
    ): bool {
        try {
            if ($path === null || $path === '') {
                return false;
            }
            $this->assertPrivatePath($tenantId, $category, $resourceKey, $path);

            return true;
        } catch (PrivateAssetIntegrityException) {
            return false;
        }
    }

    /**
     * @param  array<string,string|null>  $referenced  path => checksum
     * @return array{referenced:int,missing:int,tampered:int,orphans:int,missing_paths:list<string>,tampered_paths:list<string>,orphan_paths:list<string>}
     */
    public function report(
        int $tenantId,
        string $category,
        array $referenced,
        string $kind,
    ): array {
        $prefix = $this->prefix($tenantId, $category);
        $known = [];
        $missing = [];
        $tampered = [];

        foreach ($referenced as $path => $checksum) {
            $known[$path] = true;
            try {
                $parts = explode('/', $path);
                $resourceKey = $parts[4] ?? '';
                $this->readVerified($tenantId, $category, $resourceKey, $path, $checksum, $kind);
            } catch (PrivateAssetIntegrityException $e) {
                if ($e->reason === 'missing') {
                    $missing[] = $path;
                } else {
                    $tampered[] = $path;
                }
            }
        }

        $orphans = array_values(array_filter(
            Storage::disk('local')->allFiles($prefix),
            fn (string $path): bool => ! isset($known[$path]),
        ));

        return [
            'referenced' => count($referenced),
            'missing' => count($missing),
            'tampered' => count($tampered),
            'orphans' => count($orphans),
            'missing_paths' => $missing,
            'tampered_paths' => $tampered,
            'orphan_paths' => $orphans,
        ];
    }

    public function assertLegacyPath(string $path, array $allowedPrefixes): void
    {
        $this->assertSafeRelativePath($path);
        foreach ($allowedPrefixes as $prefix) {
            $normalized = trim((string) $prefix, '/').'/';
            if (str_starts_with($path, $normalized)) {
                return;
            }
        }

        throw new PrivateAssetIntegrityException('invalid_path', 'Asset path is outside the approved legacy namespace.', 409);
    }

    /** @return array{sha256:string,mime:string,size:int,width:int,height:int} */
    public function validatePng(string $bytes): array
    {
        $size = strlen($bytes);
        if ($size < 24 || $size > self::MAX_PNG_BYTES || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw new PrivateAssetIntegrityException('invalid_png', 'Image must be a valid PNG within 2 MB.', 422);
        }

        $image = @getimagesizefromstring($bytes);
        if ($image === false || ($image['mime'] ?? null) !== 'image/png') {
            throw new PrivateAssetIntegrityException('invalid_png', 'Image content is not a valid PNG.', 422);
        }
        [$width, $height] = $image;
        if ($width < 1 || $height < 1
            || $width > self::MAX_IMAGE_DIMENSION
            || $height > self::MAX_IMAGE_DIMENSION
            || $width * $height > self::MAX_IMAGE_PIXELS) {
            throw new PrivateAssetIntegrityException('invalid_dimensions', 'PNG dimensions exceed the allowed bounds.', 422);
        }

        return [
            'sha256' => hash('sha256', $bytes),
            'mime' => 'image/png',
            'size' => $size,
            'width' => $width,
            'height' => $height,
        ];
    }

    /** @return array{sha256:string,mime:string,size:int} */
    public function validatePdf(string $bytes): array
    {
        $size = strlen($bytes);
        $tail = substr($bytes, max(0, $size - 2048));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($size < 8 || $size > self::MAX_PDF_BYTES
            || ! str_starts_with($bytes, '%PDF-')
            || ! str_contains($tail, '%%EOF')
            || $mime !== 'application/pdf') {
            throw new PrivateAssetIntegrityException('invalid_pdf', 'File content is not a valid bounded PDF.', 422);
        }

        return [
            'sha256' => hash('sha256', $bytes),
            'mime' => 'application/pdf',
            'size' => $size,
        ];
    }

    private function newPath(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        string $extension,
    ): string {
        $prefix = $this->prefix($tenantId, $category, $resourceKey);
        $field = $this->safeSegment($field);

        return $prefix.'/'.$field.'-'.Str::uuid()->toString().'.'.$extension;
    }

    private function prefix(int $tenantId, string $category, string|int|null $resourceKey = null): string
    {
        if ($tenantId < 1) {
            throw new PrivateAssetIntegrityException('invalid_tenant', 'Tenant identifier is invalid.', 409);
        }
        $category = $this->safeSegment($category);
        $prefix = 'private/tenants/'.$tenantId.'/'.$category;

        return $resourceKey === null ? $prefix : $prefix.'/'.$this->safeSegment((string) $resourceKey);
    }

    private function safeSegment(string $segment): string
    {
        $segment = strtolower($segment);
        if (! preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $segment)) {
            throw new PrivateAssetIntegrityException('invalid_path', 'Asset namespace contains an invalid segment.', 409);
        }

        return $segment;
    }

    private function assertPrivatePath(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $path,
    ): void {
        $this->assertSafeRelativePath($path);
        $prefix = $this->prefix($tenantId, $category, $resourceKey).'/';
        if (! str_starts_with($path, $prefix)) {
            throw new PrivateAssetIntegrityException('invalid_path', 'Private asset is outside the tenant namespace.', 409);
        }
    }

    private function assertSafeRelativePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('#(^|/)\.\.?(?:/|$)#', $path)) {
            throw new PrivateAssetIntegrityException('invalid_path', 'Asset path is invalid.', 409);
        }
    }

    private function assertChecksum(?string $checksum): void
    {
        if ($checksum === null || ! preg_match('/\A[a-f0-9]{64}\z/D', strtolower($checksum))) {
            throw new PrivateAssetIntegrityException('missing_checksum', 'Asset integrity metadata is unavailable.', 409);
        }
    }

    /** @template T of array<string,mixed> @param T $metadata @return T&array{path:string} */
    private function writeVerified(string $path, string $bytes, array $metadata): array
    {
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new PrivateAssetIntegrityException('write_failed', 'Private asset could not be stored.', 500);
        }

        try {
            if (PHP_OS_FAMILY !== 'Windows') {
                @chmod(Storage::disk('local')->path($path), 0640);
            }

            $stored = Storage::disk('local')->get($path);
            if (! hash_equals($metadata['sha256'], hash('sha256', $stored))) {
                throw new PrivateAssetIntegrityException('write_verification_failed', 'Private asset write verification failed.', 500);
            }
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return ['path' => $path, ...$metadata];
    }
}
