<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class ProjectAttachmentPrivateStorage
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public const PROVIDER = 'local_private';

    /** @return array{path:string,sha256:string,mime:string,size:int} */
    public function storeUpload(
        UploadedFile $file,
        int $tenantId,
        int $projectId,
        int $taskId,
    ): array {
        $realPath = $file->getRealPath();
        if (! is_string($realPath) || ! is_readable($realPath)) {
            throw new RuntimeException('Temporary attachment file is unreadable.');
        }

        $bytes = file_get_contents($realPath);
        if ($bytes === false) {
            throw new RuntimeException('Temporary attachment file could not be read.');
        }

        return $this->storeBytes(
            $bytes,
            $file->getClientOriginalName(),
            $file->getMimeType() ?: 'application/octet-stream',
            $tenantId,
            $projectId,
            $taskId,
        );
    }

    /** @return array{path:string,sha256:string,mime:string,size:int} */
    public function storeBytes(
        string $bytes,
        string $originalName,
        string $expectedMime,
        int $tenantId,
        int $projectId,
        int $taskId,
    ): array {
        $metadata = $this->validate($bytes, $originalName, $expectedMime);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = preg_match('/\A[a-z0-9]{1,8}\z/D', $extension) ? $extension : 'bin';
        $path = sprintf(
            'private/tenants/%d/project-attachments/projects/%d/tasks/%d/%s.%s',
            $this->positive($tenantId, 'tenant'),
            $this->positive($projectId, 'project'),
            $this->positive($taskId, 'task'),
            Str::uuid()->toString(),
            $extension,
        );

        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new RuntimeException('Private attachment could not be stored.');
        }

        try {
            if (PHP_OS_FAMILY !== 'Windows') {
                @chmod(Storage::disk('local')->path($path), 0640);
            }
            $stored = Storage::disk('local')->get($path);
            if (! hash_equals($metadata['sha256'], hash('sha256', $stored))) {
                throw new RuntimeException('Private attachment write verification failed.');
            }
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return ['path' => $path, ...$metadata];
    }

    /** @return resource */
    public function readVerified(
        int $tenantId,
        int $projectId,
        int $taskId,
        string $path,
        ?string $expectedSha256,
        string $originalName,
        string $expectedMime,
        int $expectedSize,
    ) {
        $this->assertPath($tenantId, $projectId, $taskId, $path);
        if (! is_string($expectedSha256) || preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1) {
            throw new RuntimeException('Private attachment integrity metadata is missing.');
        }
        if (! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Private attachment is missing.');
        }

        $bytes = Storage::disk('local')->get($path);
        if ($expectedSize < 1 || strlen($bytes) !== $expectedSize) {
            throw new RuntimeException('Private attachment size verification failed.');
        }
        if (! hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Private attachment integrity verification failed.');
        }
        $this->validate($bytes, $originalName, $expectedMime);

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false || fwrite($stream, $bytes) !== strlen($bytes)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new RuntimeException('Private attachment stream could not be created.');
        }
        rewind($stream);

        return $stream;
    }

    public function deleteVerified(int $tenantId, int $projectId, int $taskId, string $path): void
    {
        $this->assertPath($tenantId, $projectId, $taskId, $path);
        if (! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Private attachment is missing; database row was preserved.');
        }
        if (! Storage::disk('local')->delete($path) || Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Private attachment deletion failed; database row was preserved.');
        }
    }

    public function restoreAtPath(
        int $tenantId,
        int $projectId,
        int $taskId,
        string $path,
        string $bytes,
        string $originalName,
        string $expectedMime,
        string $expectedSha256,
    ): void {
        $this->assertPath($tenantId, $projectId, $taskId, $path);
        $metadata = $this->validate($bytes, $originalName, $expectedMime);
        if (! hash_equals($expectedSha256, $metadata['sha256'])) {
            throw new RuntimeException('Private attachment restore checksum mismatch.');
        }
        try {
            if (! Storage::disk('local')->put($path, $bytes)
                || ! hash_equals($expectedSha256, hash('sha256', Storage::disk('local')->get($path)))) {
                throw new RuntimeException('Private attachment restore failed.');
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                @chmod(Storage::disk('local')->path($path), 0640);
            }
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function quarantineForDeletion(
        int $tenantId,
        int $projectId,
        int $taskId,
        string $path,
        string $expectedSha256,
        string $originalName,
        string $expectedMime,
        int $expectedSize,
    ): string {
        $stream = $this->readVerified(
            $tenantId,
            $projectId,
            $taskId,
            $path,
            $expectedSha256,
            $originalName,
            $expectedMime,
            $expectedSize,
        );
        fclose($stream);

        $quarantine = sprintf(
            'private/quarantine/project-attachments/%d/%s',
            $tenantId,
            Str::uuid()->toString(),
        );
        if (! Storage::disk('local')->move($path, $quarantine)
            || Storage::disk('local')->exists($path)
            || ! Storage::disk('local')->exists($quarantine)
            || ! hash_equals($expectedSha256, hash('sha256', Storage::disk('local')->get($quarantine)))) {
            if (Storage::disk('local')->exists($quarantine) && ! Storage::disk('local')->exists($path)) {
                Storage::disk('local')->move($quarantine, $path);
            }
            throw new RuntimeException('Private attachment quarantine failed; database row was preserved.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod(Storage::disk('local')->path($quarantine), 0600);
        }

        return $quarantine;
    }

    public function restoreQuarantined(string $quarantine, string $path, string $expectedSha256): void
    {
        if (! str_starts_with($quarantine, 'private/quarantine/project-attachments/')
            || str_contains($quarantine, '..') || str_contains($quarantine, "\0")
            || ! Storage::disk('local')->exists($quarantine)
            || ! hash_equals($expectedSha256, hash('sha256', Storage::disk('local')->get($quarantine)))
            || ! Storage::disk('local')->move($quarantine, $path)
            || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Private attachment quarantine restore failed.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod(Storage::disk('local')->path($path), 0640);
        }
    }

    public function purgeQuarantined(string $quarantine): bool
    {
        if (! str_starts_with($quarantine, 'private/quarantine/project-attachments/')
            || str_contains($quarantine, '..') || str_contains($quarantine, "\0")) {
            return false;
        }

        return ! Storage::disk('local')->exists($quarantine)
            || (Storage::disk('local')->delete($quarantine) && ! Storage::disk('local')->exists($quarantine));
    }

    /** @return array{sha256:string,mime:string,size:int} */
    public function validate(string $bytes, string $originalName, string $expectedMime): array
    {
        $size = strlen($bytes);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new RuntimeException('Attachment size is outside the permitted bounds.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = (array) config("project_attachments.mime_types_by_extension.{$extension}", []);
        if ($allowed === [] || ! in_array($expectedMime, $allowed, true)) {
            throw new RuntimeException('Attachment extension and MIME metadata do not match policy.');
        }

        $prefix = substr($bytes, 0, 4096);
        if (preg_match('/^\s*(?:<!doctype\s+html|<html\b|<script\b|<svg\b|<\?xml\b)/i', $prefix) === 1) {
            throw new RuntimeException('Active web content is not permitted as an attachment.');
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $image = @getimagesizefromstring($bytes);
            if ($image === false || ! in_array($image['mime'] ?? '', $allowed, true)) {
                throw new RuntimeException('Attachment image content is invalid.');
            }
            [$width, $height] = $image;
            if ($width < 1 || $height < 1 || $width > 8192 || $height > 8192 || $width * $height > 32_000_000) {
                throw new RuntimeException('Attachment image dimensions exceed policy.');
            }
            $detected = (string) $image['mime'];
        } elseif ($extension === 'pdf') {
            $tail = substr($bytes, max(0, $size - 2048));
            if (! str_starts_with($bytes, '%PDF-') || ! str_contains($tail, '%%EOF') || $detected !== 'application/pdf') {
                throw new RuntimeException('Attachment PDF content is invalid.');
            }
        } elseif (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            if (! str_starts_with($bytes, "PK\x03\x04") || ! in_array($detected, ['application/zip', 'application/x-zip-compressed'], true)) {
                throw new RuntimeException('Attachment OOXML container is invalid.');
            }
        } elseif (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
            if (! str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
                throw new RuntimeException('Attachment Office binary content is invalid.');
            }
        } elseif (! in_array($detected, $allowed, true)) {
            throw new RuntimeException('Attachment content MIME does not match policy.');
        }

        return [
            'sha256' => hash('sha256', $bytes),
            'mime' => $expectedMime,
            'size' => $size,
        ];
    }

    private function assertPath(int $tenantId, int $projectId, int $taskId, string $path): void
    {
        if (str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, '..')) {
            throw new RuntimeException('Private attachment path is invalid.');
        }
        $expected = sprintf(
            'private/tenants/%d/project-attachments/projects/%d/tasks/%d/',
            $this->positive($tenantId, 'tenant'),
            $this->positive($projectId, 'project'),
            $this->positive($taskId, 'task'),
        );
        if (! str_starts_with($path, $expected)) {
            throw new RuntimeException('Private attachment is outside the authorized project namespace.');
        }
    }

    private function positive(int $value, string $label): int
    {
        if ($value < 1) {
            throw new RuntimeException("Invalid {$label} identifier.");
        }

        return $value;
    }
}
