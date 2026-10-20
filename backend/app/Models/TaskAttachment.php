<?php

namespace App\Models;

use App\Services\ProjectAttachmentPrivateStorage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'user_id',
        'filename',
        'original_name',
        'mime_type',
        'size_bytes',
        'path',
        'storage_provider',
        'drive_file_id',
        'drive_web_view_link',
        'drive_download_link',
        'content_sha256',
        'source_tombstoned_at',
        'source_tombstone_reason',
    ];

    /**
     * Storage implementation details are never part of the API contract.
     * Clients receive only the authenticated proxy URLs from the accessors.
     */
    protected $hidden = [
        'path',
        'drive_file_id',
        'drive_web_view_link',
        'drive_download_link',
        'content_sha256',
        'source_tombstoned_at',
        'source_tombstone_reason',
    ];

    protected $appends = ['url', 'download_url'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Primary "open in browser" link. Resolution order by provider:
     *   - google_drive  → drive_web_view_link (open in Drive viewer)
     *   - r2            → backend proxy stream URL (always reachable even
     *                     if R2 public URL is ISP-blocked). The proxy
     *                     handles auth + content-disposition headers.
     *   - local (else)  → Laravel public storage URL
     *
     * Returns '' if nothing resolvable - caller (frontend) hides the link.
     */
    public function getUrlAttribute(): string
    {
        return in_array($this->storage_provider, [ProjectAttachmentPrivateStorage::PROVIDER, 'r2'], true) && $this->path
            ? $this->proxyStreamUrl()
            : '';
    }

    /**
     * Direct download URL - adds ?download=1 so the proxy emits
     * Content-Disposition: attachment instead of inline.
     */
    public function getDownloadUrlAttribute(): string
    {
        return in_array($this->storage_provider, [ProjectAttachmentPrivateStorage::PROVIDER, 'r2'], true) && $this->path
            ? $this->proxyStreamUrl().'?download=1'
            : '';
    }

    /**
     * Build the URL to the backend stream endpoint. Falls back to '' if the
     * task relation isn't loaded - caller will get an empty link.
     */
    private function proxyStreamUrl(): string
    {
        $task = $this->task;
        if (! $task) {
            return '';
        }

        return url("/api/v1/projects/{$task->project_id}/tasks/{$task->id}/attachments/{$this->id}/stream");
    }
}
