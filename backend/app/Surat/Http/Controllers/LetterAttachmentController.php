<?php

namespace App\Surat\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Surat\Models\Letter;
use App\Surat\Models\LetterAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class LetterAttachmentController extends ApiController
{
    public function index(Request $request, string $publicId): JsonResponse
    {
        $this->requireAttachmentCapability($request, 'documents.letter.view', 'list', $publicId);
        $letter = $this->letter($request, $publicId);

        $attachments = $letter->attachments()
            ->with('uploadedBy:id,name')
            ->get()
            ->map(fn (LetterAttachment $attachment): array => $this->attachmentPayload($attachment))
            ->values();

        return $this->success($attachments);
    }

    public function store(Request $request, string $publicId): JsonResponse
    {
        $this->requireAttachmentCapability($request, 'documents.letter.manage', 'upload', $publicId);
        $letter = $this->letter($request, $publicId);
        $request->user()->can('update', $letter) ?: abort(403, 'Tidak boleh upload lampiran ke surat ini.');
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,png,jpg,jpeg,doc,docx,xls,xlsx,zip'],
        ]);

        $file = $request->file('file');
        $directory = $this->privateDirectory((int) $request->user()->company_id, (int) $letter->id);
        $path = $file->store($directory, 'local');
        if (! is_string($path) || $path === '') {
            abort(500, 'Lampiran gagal disimpan ke penyimpanan privat.');
        }

        try {
            $att = LetterAttachment::create([
                'letter_id' => $letter->id,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
                'uploaded_by_id' => $request->user()->id,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        $att->load('uploadedBy:id,name');

        return $this->created($this->attachmentPayload($att));
    }

    public function destroy(Request $request, string $publicId, int $attId): JsonResponse
    {
        $this->requireAttachmentCapability($request, 'documents.letter.manage', 'delete', $publicId);
        $letter = $this->letter($request, $publicId);
        $request->user()->can('update', $letter) ?: abort(403);
        $att = LetterAttachment::where('id', $attId)->where('letter_id', $letter->id)->firstOrFail();
        $path = $this->validatedPrivatePath($att, $request, $letter);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 409, 'Lampiran privat tidak ditemukan; penghapusan dibatalkan.');
        $quarantine = 'private/.quarantine/letters/attachments/'.Str::uuid();
        abort_unless($disk->move($path, $quarantine), 500, 'Lampiran privat gagal dikarantina.');

        try {
            DB::transaction(fn () => $att->delete());
        } catch (Throwable $exception) {
            if ($disk->exists($quarantine)) {
                $disk->move($quarantine, $path);
            }

            throw $exception;
        }
        $disk->delete($quarantine);

        return $this->success(null, 'Lampiran dihapus');
    }

    public function download(Request $request, string $publicId, int $attId)
    {
        $this->requireAttachmentCapability($request, 'documents.letter.view', 'download', $publicId);
        $letter = $this->letter($request, $publicId);
        $request->user()->can('view', $letter) ?: abort(403);
        $att = LetterAttachment::where('id', $attId)->where('letter_id', $letter->id)->firstOrFail();

        $path = $this->validatedPrivatePath($att, $request, $letter);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404, 'Lampiran tidak ditemukan.');

        if ($att->checksum) {
            $actualChecksum = hash_file('sha256', $disk->path($path));
            abort_unless(
                is_string($actualChecksum) && hash_equals($att->checksum, $actualChecksum),
                409,
                'Integritas lampiran tidak dapat diverifikasi.',
            );
        }

        return $disk->download($path, $this->safeFilename($att->original_name), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    private function letter(Request $request, string $publicId): Letter
    {
        return Letter::where('public_id', $publicId)
            ->where('company_id', $request->user()->company_id)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function attachmentPayload(LetterAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
            'checksum' => $attachment->checksum,
            'uploaded_by' => $attachment->uploadedBy ? [
                'id' => $attachment->uploadedBy->id,
                'name' => $attachment->uploadedBy->name,
            ] : null,
            'created_at' => optional($attachment->created_at)->toIso8601String(),
        ];
    }

    private function validatedPrivatePath(
        LetterAttachment $attachment,
        Request $request,
        Letter $letter,
    ): string {
        $path = ltrim((string) $attachment->path, '/');
        $expectedPrefix = $this->privateDirectory(
            (int) $request->user()->company_id,
            (int) $letter->id,
        ).'/';

        abort_unless(
            str_starts_with($path, $expectedPrefix)
                && ! preg_match('#(?:\.\.|\\\\|\x00)#', $path),
            409,
            'Lokasi lampiran bukan penyimpanan privat tenant ini.',
        );

        return $path;
    }

    private function privateDirectory(int $companyId, int $letterId): string
    {
        return "private/letters/attachments/{$companyId}/{$letterId}";
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F\"\\r\\n]+/', '_', $filename) ?: 'attachment';

        return mb_substr($filename, 0, 180);
    }

    private function requireAttachmentCapability(
        Request $request,
        string $capability,
        string $action,
        string $resourceId,
    ): void {
        $this->requireGranularCapability(
            $request->user(),
            $capability,
            'admin.access',
            'Letter attachment capability required.',
            [
                'company_id' => $request->user()->company_id,
                'resource_type' => 'letter_attachment',
                'resource_id' => $resourceId,
                'action' => $action,
            ],
        );
    }
}
