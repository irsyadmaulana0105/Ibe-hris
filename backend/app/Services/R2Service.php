<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cloudflare R2 storage wrapper for task attachments. Wraps the Laravel
 * 's3' disk pointed at R2 with attachment-specific conventions:
 *
 *  - Object key layout: projects/{project_id}/tasks/{task_id}/{random}.{ext}
 *    Keeps blast radius of "delete a project" obvious in the R2 console.
 *  - URL strategy: if R2_PUBLIC_URL is set (custom domain or r2.dev), uses
 *    that for unauthenticated reads. Otherwise issues a presigned URL with
 *    1-hour TTL on each download.
 *  - Fallback: callers should check isConfigured() and use local disk if
 *    R2 isn't set up (matches the old GoogleDriveService contract).
 */
class R2Service
{
    public function isConfigured(): bool
    {
        return $this->configurationErrors() === [];
    }

    /** @return list<string> */
    public function configurationErrors(): array
    {
        $disk = (array) config('filesystems.disks.r2', []);
        $required = ['key', 'secret', 'bucket', 'endpoint'];
        $errors = [];
        foreach ($required as $field) {
            if (! is_string($disk[$field] ?? null) || trim($disk[$field]) === '') {
                $errors[] = $field;
            }
        }
        if (isset($disk['endpoint']) && $disk['endpoint'] !== '' && ! filter_var($disk['endpoint'], FILTER_VALIDATE_URL)) {
            $errors[] = 'endpoint_url';
        }

        return array_values(array_unique($errors));
    }

    /**
     * Upload an HTTP upload to R2 under projects/{p}/tasks/{t}/{rand}.{ext}.
     *
     * @return array{key:string,size:int,public_url:?string}
     */
    public function uploadForTask(UploadedFile $file, int $projectId, int $taskId): array
    {
        $this->assertConfigured();

        $ext = $file->getClientOriginalExtension() ?: $file->extension();
        $filename = Str::random(32).($ext ? '.'.$ext : '');
        $key = "projects/{$projectId}/tasks/{$taskId}/{$filename}";

        $realPath = $file->getRealPath();
        if (! $realPath || ! is_readable($realPath)) {
            throw new RuntimeException('Temporary upload file tidak dapat dibaca. Periksa upload_tmp_dir PHP.');
        }

        $stream = fopen($realPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Gagal membuka temporary upload file.');
        }
        try {
            $stored = $this->disk()->put($key, $stream, [
                'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $stored) {
            throw new RuntimeException('Cloudflare R2 menolak penyimpanan object.');
        }

        return [
            'key' => $key,
            'size' => (int) $file->getSize(),
            'public_url' => $this->publicUrl($key),
        ];
    }

    /** Upload a public branding asset at an explicit object key. */
    public function putFile(string $key, UploadedFile|string $file, ?string $contentType = null): string
    {
        $this->assertConfigured();
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $mime = $contentType ?: ($file instanceof UploadedFile ? $file->getMimeType() : mime_content_type($path));
        $stream = fopen($path, 'rb');

        try {
            $stored = $this->disk()->put($key, $stream, [
                'ContentType' => $mime ?: 'application/octet-stream',
                'visibility' => 'public',
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $stored) {
            throw new RuntimeException("Failed to upload branding asset: {$key}");
        }

        return $this->publicUrl($key) ?: $this->disk()->url($key);
    }

    /**
     * Delete an object. Idempotent on missing key.
     */
    public function delete(string $key): void
    {
        $this->assertConfigured();
        $this->disk()->delete($key);
    }

    /**
     * Resolve a usable URL for the client:
     *  - public_url if bucket is public-accessible (R2.dev or custom domain)
     *  - else a presigned URL with 1-hour expiry
     */
    public function urlFor(string $key): string
    {
        $public = $this->publicUrl($key);
        if ($public) {
            return $public;
        }

        return $this->disk()->temporaryUrl($key, now()->addHour());
    }

    /**
     * Read an object as a stream resource for proxy/streaming responses.
     * Caller is responsible for fclose(). Returns false if the key doesn't exist.
     *
     * Used by the backend proxy endpoint when the R2 public URL is unreachable
     * (e.g. ISP blocks *.r2.dev). Backend fetches from R2 and pipes to client.
     *
     * @return resource|false
     */
    public function readStream(string $key)
    {
        $this->assertConfigured();

        return $this->disk()->readStream($key);
    }

    public function exists(string $key): bool
    {
        $this->assertConfigured();

        return $this->disk()->exists($key);
    }

    private function publicUrl(string $key): ?string
    {
        $base = config('filesystems.disks.r2.url');
        if (! $base) {
            return null;
        }

        return rtrim($base, '/').'/'.ltrim($key, '/');
    }

    private function disk(): Filesystem
    {
        return Storage::disk('r2');
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Cloudflare R2 belum valid. Field bermasalah: '.implode(', ', $this->configurationErrors()).'.',
            );
        }
    }
}
