<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin wrapper around the Google Drive v3 API, authenticated with a service
 * account. Used to keep task attachments off the VPS disk: PHP receives the
 * upload in its tmp directory, this service streams it to Drive, then PHP
 * cleans up the tmp on its own.
 *
 * Setup checklist (one-time):
 *  1. Google Cloud Console → create project → enable "Google Drive API"
 *  2. Create a Service Account, download JSON key file
 *  3. Place JSON at `storage/app/google-credentials.json` (gitignored)
 *  4. Set in .env:
 *       GOOGLE_SERVICE_ACCOUNT_KEY_PATH=storage/app/google-credentials.json
 *       GOOGLE_DRIVE_FOLDER_ID=<id of a folder you share with the service account>
 *  5. In Google Drive, share the target folder with the service account email
 *     (granular access = Editor). Service accounts can't browse Drive UIs but
 *     can read/write files where they have access.
 *
 * Quota note: a free service account has 15 GB. For more, share a Workspace
 * Shared Drive with the service account - the service account then writes
 * into Shared Drive storage (workspace quota).
 */
class GoogleDriveService
{
    private ?GoogleDrive $service = null;

    public function isConfigured(): bool
    {
        $path = config('services.google_drive.key_path');
        return $path && file_exists(base_path($path));
    }

    /**
     * Upload a local file to Drive. Returns metadata to persist on the
     * attachment row. Caller is responsible for the local cleanup.
     *
     * @return array{id:string,web_view_link:string,download_link:string,size:int}
     */
    public function upload(
        string $localPath,
        string $displayName,
        ?string $mimeType,
        ?string $parentFolderId = null,
    ): array {
        $this->assertConfigured();

        if (! is_readable($localPath)) {
            throw new RuntimeException("Local file not readable: $localPath");
        }

        $parentId = $parentFolderId ?: config('services.google_drive.root_folder_id') ?: null;

        $file = new DriveFile([
            'name'    => $displayName,
            'parents' => $parentId ? [$parentId] : [],
        ]);

        $content = file_get_contents($localPath);
        $created = $this->drive()->files->create($file, [
            'data'       => $content,
            'mimeType'   => $mimeType ?: 'application/octet-stream',
            'uploadType' => 'multipart',
            'fields'     => 'id, name, size, webViewLink, webContentLink',
            'supportsAllDrives' => true,
        ]);

        // Make readable by anyone with the link so the frontend can preview
        // without needing OAuth on the user side. If you want stricter access,
        // skip this and proxy downloads through the backend.
        $this->drive()->permissions->create(
            $created->id,
            new Permission(['type' => 'anyone', 'role' => 'reader']),
            ['supportsAllDrives' => true],
        );

        // webContentLink is a "download" URL; webViewLink is "open in Drive viewer".
        return [
            'id'             => $created->id,
            'web_view_link'  => $created->webViewLink ?? '',
            'download_link'  => $created->webContentLink
                ?? "https://drive.google.com/uc?id={$created->id}&export=download",
            'size'           => (int) ($created->size ?? 0),
        ];
    }

    /**
     * Delete a file from Drive. Idempotent on 404 (already gone).
     */
    public function delete(string $fileId): void
    {
        $this->assertConfigured();

        try {
            $this->drive()->files->delete($fileId, ['supportsAllDrives' => true]);
        } catch (\Google\Service\Exception $e) {
            // 404 = already deleted, treat as success
            if ($e->getCode() !== 404) {
                Log::warning('GoogleDriveService delete failed', [
                    'file_id' => $fileId,
                    'message' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }

    /**
     * Find-or-create a sub-folder named $folderName under $parentId. Returns
     * the folder ID. Used to keep one folder per project in Drive.
     */
    public function ensureFolder(string $folderName, ?string $parentId = null): string
    {
        $this->assertConfigured();

        $parent = $parentId ?: config('services.google_drive.root_folder_id') ?: 'root';

        $escapedName = str_replace("'", "\\'", $folderName);
        $query = "name = '$escapedName' and mimeType = 'application/vnd.google-apps.folder' and trashed = false and '$parent' in parents";

        $existing = $this->drive()->files->listFiles([
            'q'      => $query,
            'fields' => 'files(id, name)',
            'pageSize' => 1,
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ]);

        if (! empty($existing->files)) {
            return $existing->files[0]->id;
        }

        $folder = new DriveFile([
            'name'     => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => $parent === 'root' ? [] : [$parent],
        ]);

        $created = $this->drive()->files->create($folder, [
            'fields' => 'id, name',
            'supportsAllDrives' => true,
        ]);

        return $created->id;
    }

    private function drive(): GoogleDrive
    {
        if ($this->service) return $this->service;

        $client = new GoogleClient();
        $keyPath = base_path(config('services.google_drive.key_path'));
        $client->setAuthConfig($keyPath);
        $client->addScope(GoogleDrive::DRIVE_FILE);
        // Note: DRIVE_FILE only grants access to files this app creates.
        // To manage existing files, switch to GoogleDrive::DRIVE.

        return $this->service = new GoogleDrive($client);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Google Drive not configured. Set GOOGLE_SERVICE_ACCOUNT_KEY_PATH in .env '
                . 'and place service account JSON file at the configured path.',
            );
        }
    }
}
