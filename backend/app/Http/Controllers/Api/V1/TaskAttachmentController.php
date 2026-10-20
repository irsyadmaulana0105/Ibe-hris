<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Services\ProjectAttachmentPrivateCutover;
use App\Services\ProjectAttachmentPrivateStorage;
use App\Services\ProjectAuthorizer;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskAttachmentController extends ApiController
{
    public function __construct(
        private readonly ProjectService $service,
        private readonly ProjectAuthorizer $authorizer,
        private readonly ProjectAttachmentPrivateStorage $privateStorage,
        private readonly ProjectAttachmentPrivateCutover $privateCutover,
    ) {}

    public function index(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorizer->ensureTaskBelongs($project, $task);
        $this->service->authorizeUserAccess($project, $request->user());
        $items = $task->attachments()->with('user:id,name,profile_photo_path')->get();

        return $this->success($items);
    }

    /**
     * POST /api/v1/projects/{project}/tasks/{task}/attachments
     * multipart/form-data field: file
     *
     * Attachments are tenant/project scoped on Laravel's non-public local disk.
     * No R2, Drive, or public-disk fallback is allowed for new uploads.
     */
    public function store(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorizer->ensureTaskBelongs($project, $task);
        $this->authorizer->ensure($request->user(), $project, 'upload_attachment');

        $request->validate([
            'file' => [
                'required', 'file', 'max:20480', // 20 MB
                'extensions:'.implode(',', config('project_attachments.allowed_extensions')),
                'mimetypes:'.implode(',', config('project_attachments.allowed_mime_types')),
            ],
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        // Store only the MIME determined from file content, never the
        // attacker-controlled multipart Content-Type header.
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $size = $file->getSize();
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedForExtension = config("project_attachments.mime_types_by_extension.{$extension}", []);
        $prefix = file_get_contents($file->getRealPath(), false, null, 0, 4096) ?: '';
        $looksLikeActiveWebContent = preg_match(
            '/^\s*(?:<!doctype\s+html|<html\b|<script\b|<svg\b|<\?xml\b)/i',
            $prefix,
        ) === 1;
        if ($looksLikeActiveWebContent || ! in_array($mimeType, $allowedForExtension, true)) {
            throw ValidationException::withMessages([
                'file' => ['Isi file tidak sesuai dengan ekstensi yang diizinkan.'],
            ]);
        }

        $meta = null;
        try {
            $meta = $this->privateStorage->storeUpload(
                $file,
                (int) $project->company_id,
                (int) $project->id,
                (int) $task->id,
            );
            $attachment = $task->attachments()->create([
                'user_id' => $request->user()->id,
                'filename' => basename($meta['path']),
                'original_name' => $originalName,
                'mime_type' => $meta['mime'],
                'size_bytes' => $meta['size'],
                'path' => $meta['path'],
                'storage_provider' => ProjectAttachmentPrivateStorage::PROVIDER,
                'content_sha256' => $meta['sha256'],
                'drive_file_id' => null,
                'drive_web_view_link' => null,
                'drive_download_link' => null,
            ]);
        } catch (\Throwable $e) {
            if (is_array($meta) && isset($meta['path'])) {
                try {
                    $this->privateStorage->deleteVerified(
                        (int) $project->company_id,
                        (int) $project->id,
                        (int) $task->id,
                        $meta['path'],
                    );
                } catch (\Throwable) {
                    Log::critical('Private project attachment cleanup failed after database error.', [
                        'project_id' => $project->id,
                        'task_id' => $task->id,
                    ]);
                }
            }
            throw $e;
        }

        $attachment->load('user:id,name,profile_photo_path');

        return $this->created($attachment, 'Lampiran ditambahkan.');
    }

    /**
     * GET /api/v1/projects/{project}/tasks/{task}/attachments/{attachment}/stream
     *
     * Authenticated proxy for tenant/project scoped private objects.
     */
    public function stream(
        Request $request,
        Project $project,
        Task $task,
        TaskAttachment $attachment,
    ): StreamedResponse|Response {
        $this->authorizer->ensureTaskBelongs($project, $task);
        $this->service->authorizeUserAccess($project, $request->user());

        if ($attachment->task_id !== $task->id) {
            abort(404);
        }

        if (! in_array($attachment->storage_provider, [ProjectAttachmentPrivateStorage::PROVIDER, 'r2'], true)
            || ! $attachment->path) {
            abort(409, 'Lampiran lama belum dipindahkan ke penyimpanan privat.');
        }

        try {
            $stream = $attachment->storage_provider === ProjectAttachmentPrivateStorage::PROVIDER
                ? $this->privateStorage->readVerified(
                    (int) $project->company_id,
                    (int) $project->id,
                    (int) $task->id,
                    $attachment->path,
                    $attachment->content_sha256,
                    $attachment->original_name,
                    $attachment->mime_type ?: 'application/octet-stream',
                    (int) $attachment->size_bytes,
                )
                : $this->privateCutover->readVerifiedLegacyCopy(
                    $attachment,
                    (int) $project->company_id,
                    (int) $project->id,
                    (int) $task->id,
                );
        } catch (\Throwable $e) {
            Log::error('Private project attachment read failed', [
                'attachment' => $attachment->id,
                'project_id' => $project->id,
            ]);
            abort(409, 'Integritas lampiran tidak dapat diverifikasi.');
        }

        $trustedInlineTypes = config('project_attachments.inline_mime_types', []);
        $isTrustedInline = in_array($attachment->mime_type, $trustedInlineTypes, true);
        $disposition = $request->boolean('download') || ! $isTrustedInline ? 'attachment' : 'inline';
        // Quote-escape original filename for Content-Disposition; non-ASCII via filename*
        $safeOriginalName = str_replace(["\r", "\n", '"', '\\'], '_', basename($attachment->original_name));
        $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $safeOriginalName) ?: 'attachment';
        $utf8Name = rawurlencode($safeOriginalName);
        $contentType = $isTrustedInline
            ? $attachment->mime_type
            : 'application/octet-stream';

        return response()->stream(
            function () use ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => $contentType,
                'Content-Length' => (string) $attachment->size_bytes,
                'Content-Disposition' => sprintf(
                    '%s; filename="%s"; filename*=UTF-8\'\'%s',
                    $disposition,
                    $asciiName,
                    $utf8Name,
                ),
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:",
            ],
        );
    }

    public function destroy(
        Request $request,
        Project $project,
        Task $task,
        TaskAttachment $attachment,
    ): JsonResponse {
        $this->authorizer->ensureTaskBelongs($project, $task);
        $this->service->authorizeUserAccess($project, $request->user());

        if ($attachment->task_id !== $task->id) {
            abort(404);
        }

        $user = $request->user();
        if ($attachment->user_id !== $user->id && ! $user->isAdmin) {
            abort(403, 'Hanya pengunggah atau admin yang bisa menghapus.');
        }

        if ($attachment->storage_provider !== ProjectAttachmentPrivateStorage::PROVIDER || ! $attachment->path) {
            abort(409, 'Lampiran lama harus dimigrasikan sebelum dapat dihapus.');
        }

        try {
            $quarantine = $this->privateStorage->quarantineForDeletion(
                (int) $project->company_id,
                (int) $project->id,
                (int) $task->id,
                $attachment->path,
                (string) $attachment->content_sha256,
                $attachment->original_name,
                $attachment->mime_type ?: 'application/octet-stream',
                (int) $attachment->size_bytes,
            );
        } catch (\Throwable) {
            abort(409, 'Lampiran gagal diverifikasi sebelum penghapusan.');
        }
        try {
            if (! $attachment->delete()) {
                throw new \RuntimeException('Attachment database deletion failed.');
            }
        } catch (\Throwable $e) {
            $this->privateStorage->restoreQuarantined(
                $quarantine,
                $attachment->path,
                (string) $attachment->content_sha256,
            );
            throw $e;
        }
        if (! $this->privateStorage->purgeQuarantined($quarantine)) {
            Log::critical('Deleted project attachment remains in inaccessible quarantine.', [
                'attachment' => $attachment->id,
                'project_id' => $project->id,
            ]);
        }

        return $this->success(message: 'Lampiran dihapus.');
    }
}
