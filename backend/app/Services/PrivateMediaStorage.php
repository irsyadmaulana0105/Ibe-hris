<?php

namespace App\Services;

use App\Exceptions\PrivateAssetIntegrityException;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Tenant-scoped private storage for bounded JPEG, PNG, and PDF business media.
 *
 * Paths are persistence-only values. API callers receive fixed entity/field
 * routes and never receive a storage path or a disk URL.
 */
class PrivateMediaStorage
{
    public const MAX_IMAGE_BYTES = 6 * 1024 * 1024;

    public const MAX_PDF_BYTES = 10 * 1024 * 1024;

    public const MAX_IMAGE_DIMENSION = 4096;

    public const MAX_IMAGE_PIXELS = 16_000_000;

    /** @return array{path:string,sha256:string,size:int,mime:string,width:?int,height:?int} */
    public function storeImageDataUri(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        string $dataUri,
    ): array {
        if (! preg_match('/\Adata:image\/(jpeg|png);base64,([A-Za-z0-9+\/=]+)\z/D', $dataUri, $matches)) {
            throw new PrivateAssetIntegrityException('invalid_encoding', 'Image must be a strict JPEG or PNG data URI.', 422);
        }

        if (strlen($matches[2]) > (int) ceil(self::MAX_IMAGE_BYTES * 4 / 3) + 8) {
            throw new PrivateAssetIntegrityException('too_large', 'Image exceeds the allowed size.', 422);
        }

        $bytes = base64_decode($matches[2], true);
        if ($bytes === false) {
            throw new PrivateAssetIntegrityException('invalid_encoding', 'Image base64 is invalid.', 422);
        }

        $declaredMime = $matches[1] === 'jpeg' ? 'image/jpeg' : 'image/png';

        return $this->storeBytes(
            $tenantId,
            $category,
            $resourceKey,
            $field,
            $bytes,
            [$declaredMime],
        );
    }

    /** @return array{path:string,sha256:string,size:int,mime:string,width:?int,height:?int} */
    public function storeUploaded(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        UploadedFile $file,
        array $allowedMimes,
    ): array {
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw new PrivateAssetIntegrityException('unreadable', 'Uploaded media could not be read.', 422);
        }

        return $this->storeBytes($tenantId, $category, $resourceKey, $field, $bytes, $allowedMimes);
    }

    /** @return array{path:string,sha256:string,size:int,mime:string,width:?int,height:?int} */
    public function storeBytes(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        string $bytes,
        array $allowedMimes,
    ): array {
        $metadata = $this->inspectBytes($bytes, $allowedMimes);
        $extension = match ($metadata['mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => throw new PrivateAssetIntegrityException('invalid_mime', 'Media type is not supported.', 422),
        };
        $path = $this->newPath($tenantId, $category, $resourceKey, $field, $extension);

        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new PrivateAssetIntegrityException('write_failed', 'Private media could not be stored.', 500);
        }

        try {
            $stored = Storage::disk('local')->get($path);
            if (! hash_equals($metadata['sha256'], hash('sha256', $stored))) {
                throw new PrivateAssetIntegrityException('write_verification_failed', 'Private media write verification failed.', 500);
            }
            $this->assertMetadata($this->inspectBytes($stored, $allowedMimes), $metadata);
            if (PHP_OS_FAMILY !== 'Windows') {
                @chmod(Storage::disk('local')->path($path), 0640);
            }
        } catch (\Throwable $failure) {
            Storage::disk('local')->delete($path);
            throw $failure;
        }

        return ['path' => $path, ...$metadata];
    }

    /** @return array{path:string,sha256:string,size:int,mime:string,width:?int,height:?int} */
    public function copyLegacyToPrivate(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        string $legacyPath,
        array $allowedPrefixes,
        array $allowedMimes,
    ): array {
        $this->assertLegacyPath($legacyPath, $allowedPrefixes);
        if (! Storage::disk('public')->exists($legacyPath)) {
            throw new PrivateAssetIntegrityException('missing', 'Referenced public media is unavailable.', 410);
        }

        return $this->storeBytes(
            $tenantId,
            $category,
            $resourceKey,
            $field,
            Storage::disk('public')->get($legacyPath),
            $allowedMimes,
        );
    }

    public function readVerified(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        string $path,
        array $expectedMetadata,
        array $allowedMimes,
    ): string {
        $this->assertPrivatePath($tenantId, $category, $resourceKey, $field, $path);
        $this->assertExpectedMetadata($expectedMetadata);
        if (! Storage::disk('local')->exists($path)) {
            throw new PrivateAssetIntegrityException('missing', 'Private media is unavailable.', 410);
        }

        $bytes = Storage::disk('local')->get($path);
        $actual = $this->inspectBytes($bytes, $allowedMimes);
        $this->assertMetadata($actual, $expectedMetadata);

        return $bytes;
    }

    public function downloadResponse(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        string $path,
        array $expectedMetadata,
        array $allowedMimes,
        string $downloadName,
        bool $inline = true,
    ): Response {
        $bytes = $this->readVerified(
            $tenantId,
            $category,
            $resourceKey,
            $field,
            $path,
            $expectedMetadata,
            $allowedMimes,
        );
        $mime = (string) $expectedMetadata['mime'];
        $sha256 = (string) $expectedMetadata['sha256'];
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '-', $downloadName) ?: 'media';

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

    public function temporarySignedRoute(string $route, array $identifiers, int $seconds): string
    {
        $seconds = max(1, min(300, $seconds));
        foreach ($identifiers as $key => $value) {
            if (str_contains((string) $key, 'path') || is_array($value) || is_object($value)) {
                throw new PrivateAssetIntegrityException('invalid_route_identifier', 'Signed media identifiers are invalid.', 409);
            }
        }

        return URL::temporarySignedRoute($route, now()->addSeconds($seconds), $identifiers);
    }

    public function deletePrivate(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        ?string $path,
    ): void {
        if ($path === null || $path === '') {
            return;
        }
        $this->assertPrivatePath($tenantId, $category, $resourceKey, $field, $path);
        if (Storage::disk('local')->exists($path)
            && (! Storage::disk('local')->delete($path) || Storage::disk('local')->exists($path))) {
            throw new PrivateAssetIntegrityException('delete_failed', 'Private media could not be deleted.', 500);
        }
    }

    public function restorePrivateExact(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        string $path,
        string $bytes,
        array $expectedMetadata,
        array $allowedMimes,
    ): void {
        $this->assertPrivatePath($tenantId, $category, $resourceKey, $field, $path);
        $actual = $this->inspectBytes($bytes, $allowedMimes);
        $this->assertMetadata($actual, $expectedMetadata);
        if (! Storage::disk('local')->put($path, $bytes)
            || ! Storage::disk('local')->exists($path)) {
            throw new PrivateAssetIntegrityException('restore_failed', 'Private media restore failed.', 500);
        }
        try {
            $this->assertMetadata(
                $this->inspectBytes(Storage::disk('local')->get($path), $allowedMimes),
                $expectedMetadata,
            );
        } catch (\Throwable $failure) {
            Storage::disk('local')->delete($path);
            throw $failure;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod(Storage::disk('local')->path($path), 0640);
        }
    }

    public function isPrivatePathFor(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        ?string $path,
    ): bool {
        if ($path === null || $path === '') {
            return false;
        }
        try {
            $this->assertPrivatePath($tenantId, $category, $resourceKey, $field, $path);

            return true;
        } catch (PrivateAssetIntegrityException) {
            return false;
        }
    }

    /** @return array{sha256:string,size:int,mime:string,width:?int,height:?int} */
    public function inspectBytes(string $bytes, array $allowedMimes): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! is_string($mime) || ! in_array($mime, $allowedMimes, true)) {
            throw new PrivateAssetIntegrityException('invalid_mime', 'Media content type is not allowed.', 422);
        }

        $size = strlen($bytes);
        $width = null;
        $height = null;
        if ($mime === 'application/pdf') {
            $tail = substr($bytes, max(0, $size - 2048));
            if ($size < 8 || $size > self::MAX_PDF_BYTES
                || ! str_starts_with($bytes, '%PDF-') || ! str_contains($tail, '%%EOF')) {
                throw new PrivateAssetIntegrityException('invalid_pdf', 'PDF content is invalid or exceeds the allowed size.', 422);
            }
        } else {
            if ($size < 24 || $size > self::MAX_IMAGE_BYTES) {
                throw new PrivateAssetIntegrityException('invalid_image', 'Image is invalid or exceeds the allowed size.', 422);
            }
            $image = @getimagesizefromstring($bytes);
            if ($image === false || ($image['mime'] ?? null) !== $mime) {
                throw new PrivateAssetIntegrityException('invalid_image', 'Image content is invalid.', 422);
            }
            [$width, $height] = $image;
            if ($width < 1 || $height < 1
                || $width > self::MAX_IMAGE_DIMENSION || $height > self::MAX_IMAGE_DIMENSION
                || $width * $height > self::MAX_IMAGE_PIXELS) {
                throw new PrivateAssetIntegrityException('invalid_dimensions', 'Image dimensions exceed the allowed bounds.', 422);
            }
        }

        return [
            'sha256' => hash('sha256', $bytes),
            'size' => $size,
            'mime' => $mime,
            'width' => $width,
            'height' => $height,
        ];
    }

    public function assertLegacyPath(string $path, array $allowedPrefixes): void
    {
        $this->assertSafeRelativePath($path);
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, trim((string) $prefix, '/').'/')) {
                return;
            }
        }

        throw new PrivateAssetIntegrityException('invalid_path', 'Media is outside an approved legacy namespace.', 409);
    }

    private function newPath(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        string $extension,
    ): string {
        if ($tenantId < 1) {
            throw new PrivateAssetIntegrityException('invalid_tenant', 'Tenant identifier is invalid.', 409);
        }

        return 'private/tenants/'.$tenantId.'/'.$this->safeSegment($category).'/'.$this->safeSegment((string) $resourceKey)
            .'/'.$this->safeSegment($field).'-'.Str::uuid()->toString().'.'.$extension;
    }

    private function assertPrivatePath(
        int $tenantId,
        string $category,
        string|int $resourceKey,
        string $field,
        string $path,
    ): void {
        $this->assertSafeRelativePath($path);
        $prefix = 'private/tenants/'.$tenantId.'/'.$this->safeSegment($category).'/'.$this->safeSegment((string) $resourceKey).'/';
        if (! str_starts_with($path, $prefix)) {
            throw new PrivateAssetIntegrityException('invalid_path', 'Private media is outside the tenant namespace.', 409);
        }
        $filename = substr($path, strlen($prefix));
        if (str_contains($filename, '/') || ! str_starts_with($filename, $this->safeSegment($field).'-')) {
            throw new PrivateAssetIntegrityException('invalid_path', 'Private media is outside the fixed field namespace.', 409);
        }
    }

    private function assertSafeRelativePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('#(^|/)\.\.?(?:/|$)#', $path)) {
            throw new PrivateAssetIntegrityException('invalid_path', 'Media path is invalid.', 409);
        }
    }

    private function safeSegment(string $segment): string
    {
        $segment = strtolower($segment);
        if (! preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $segment)) {
            throw new PrivateAssetIntegrityException('invalid_path', 'Media namespace is invalid.', 409);
        }

        return $segment;
    }

    private function assertExpectedMetadata(array $metadata): void
    {
        if (! isset($metadata['sha256'], $metadata['size'], $metadata['mime'])
            || ! is_string($metadata['sha256'])
            || ! preg_match('/\A[a-f0-9]{64}\z/D', strtolower($metadata['sha256']))
            || ! is_numeric($metadata['size']) || (int) $metadata['size'] < 1
            || ! is_string($metadata['mime']) || $metadata['mime'] === '') {
            throw new PrivateAssetIntegrityException('missing_metadata', 'Media integrity metadata is unavailable.', 409);
        }
    }

    private function assertMetadata(array $actual, array $expected): void
    {
        $this->assertExpectedMetadata($expected);
        $matches = hash_equals(strtolower((string) $expected['sha256']), $actual['sha256'])
            && (int) $expected['size'] === $actual['size']
            && (string) $expected['mime'] === $actual['mime'];
        if ($actual['mime'] !== 'application/pdf') {
            $matches = $matches
                && isset($expected['width'], $expected['height'])
                && (int) $expected['width'] === $actual['width']
                && (int) $expected['height'] === $actual['height'];
        }
        if (! $matches) {
            throw new PrivateAssetIntegrityException('tampered', 'Private media integrity verification failed.', 409);
        }
    }
}
