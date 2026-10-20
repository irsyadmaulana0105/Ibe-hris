<?php

namespace App\Services;

use App\Models\CompanyDocument;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * DMS service: register documents, upload versions, soft-delete, expiry alerts.
 *
 * Files stored on local disk under storage/app/private/dms/{company_id}/{doc_id}/v{n}_{filename}.
 * Hash recorded for integrity check.
 */
class CompanyDocumentService
{
    public function generateDocCode(int $companyId): string
    {
        $year = date('Y');
        return DB::transaction(function () use ($companyId, $year) {
            $last = CompanyDocument::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('doc_code', 'like', "DOC-{$year}-%")
                ->orderByDesc('doc_code')
                ->lockForUpdate()
                ->value('doc_code');
            $seq = $last ? ((int) substr($last, -4)) + 1 : 1;
            return "DOC-{$year}-" . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }

    public function register(User $actor, array $data, ?UploadedFile $file = null): CompanyDocument
    {
        return DB::transaction(function () use ($actor, $data, $file) {
            $doc = CompanyDocument::create([
                'company_id'        => $actor->company_id,
                'doc_code'          => $this->generateDocCode($actor->company_id),
                'title'             => $data['title'],
                'category'          => $data['category'] ?? 'other',
                'description'       => $data['description'] ?? null,
                'owner_dept'        => $data['owner_dept'] ?? null,
                'owner_user_id'     => $data['owner_user_id'] ?? null,
                'tags'              => $data['tags'] ?? [],
                'current_version'   => 'v1',
                'effective_date'    => $data['effective_date'] ?? null,
                'expiry_date'       => $data['expiry_date'] ?? null,
                'alert_days_before' => $data['alert_days_before'] ?? 30,
                'status'            => 'active',
                'created_by'        => $actor->id,
            ]);

            if ($file) {
                $this->uploadVersion($actor, $doc, $file, $data['change_notes'] ?? 'Initial version');
            }
            return $doc;
        });
    }

    public function uploadVersion(User $actor, CompanyDocument $doc, UploadedFile $file, ?string $notes = null): DocumentVersion
    {
        return DB::transaction(function () use ($actor, $doc, $file, $notes) {
            // Compute next version label
            $latest = DocumentVersion::where('document_id', $doc->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('version_label');
            $nextSeq = $latest ? ((int) substr($latest, 1)) + 1 : 1;
            $label = "v{$nextSeq}";

            // Store file
            $dir = "dms/{$doc->company_id}/{$doc->id}";
            $cleanName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());
            $stored = $file->storeAs($dir, "{$label}_{$cleanName}", 'local');

            $hash = hash_file('sha256', $file->getRealPath());

            // Unset current on prior versions
            DocumentVersion::where('document_id', $doc->id)->update(['is_current' => false]);

            $version = DocumentVersion::create([
                'document_id'       => $doc->id,
                'version_label'     => $label,
                'file_path'         => $stored,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type'         => $file->getMimeType(),
                'file_size_bytes'   => $file->getSize(),
                'file_hash_sha256'  => $hash,
                'change_notes'      => $notes,
                'is_current'        => true,
                'uploaded_by'       => $actor->id,
                'uploaded_at'       => now(),
            ]);

            $doc->update(['current_version' => $label]);
            return $version;
        });
    }

    public function expiringList(int $companyId, int $withinDays = 60): array
    {
        $rows = CompanyDocument::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays($withinDays)->toDateString())
            ->orderBy('expiry_date')
            ->get();

        return $rows->map(fn ($d) => [
            'id'              => $d->id,
            'doc_code'        => $d->doc_code,
            'title'           => $d->title,
            'category'        => $d->category,
            'expiry_date'     => $d->expiry_date?->toDateString(),
            'days_remaining'  => (int) round(now()->diffInDays($d->expiry_date, false)),
            'is_expired'      => $d->is_expired,
        ])->all();
    }
}
