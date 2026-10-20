<?php

namespace App\Console\Commands;

use App\Models\TaskAttachment;
use App\Services\GoogleDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-shot migration: walk every local task attachment, push the file to
 * Google Drive, replace the row's storage_provider + drive_* fields, then
 * delete the local copy from `storage/app/public/task-attachments/`.
 *
 * Usage:
 *   php artisan attachments:migrate-to-drive          # run for real
 *   php artisan attachments:migrate-to-drive --dry    # preview without touching anything
 *   php artisan attachments:migrate-to-drive --keep   # upload but don't delete local copy
 */
class MigrateAttachmentsToDrive extends Command
{
    protected $signature = 'attachments:migrate-to-drive
                            {--dry : List files that would be migrated, no API calls}
                            {--keep : Upload to Drive but keep the local file as backup}';

    protected $description = 'Move existing local task attachments into Google Drive (frees VPS disk)';

    public function handle(GoogleDriveService $drive): int
    {
        if (! $this->option('dry') && ! $drive->isConfigured()) {
            $this->error('Google Drive belum ter-konfigurasi. Set GOOGLE_SERVICE_ACCOUNT_KEY_PATH di .env dulu.');
            return self::FAILURE;
        }

        $dry  = $this->option('dry');
        $keep = $this->option('keep');

        $items = TaskAttachment::query()
            ->where('storage_provider', 'local')
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->with('task.project:id,name,drive_folder_id')
            ->get();

        if ($items->isEmpty()) {
            $this->info('Tidak ada attachment lokal yang perlu dimigrasi.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Ditemukan %d attachment lokal.', $items->count()));
        if ($dry) {
            foreach ($items as $a) {
                $exists = Storage::disk('public')->exists($a->path) ? 'OK' : 'MISSING';
                $this->line(sprintf(
                    '  #%-5d  %s  [%s]  %s',
                    $a->id, $a->original_name, $this->humanBytes((int) $a->size_bytes), $exists,
                ));
            }
            $this->warn('--dry mode: tidak ada perubahan yang dilakukan.');
            return self::SUCCESS;
        }

        $ok = 0;
        $skipped = 0;
        $failed = 0;
        $folderCache = []; // project_id => folder_id (in-memory, also persisted via project row)

        $bar = $this->output->createProgressBar($items->count());
        $bar->start();

        foreach ($items as $a) {
            try {
                $absolutePath = Storage::disk('public')->path($a->path);
                if (! is_file($absolutePath)) {
                    $this->newLine();
                    $this->warn("File hilang untuk attachment #{$a->id}: {$a->path} - di-skip.");
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                $project = $a->task?->project;
                if (! $project) {
                    $this->newLine();
                    $this->warn("Attachment #{$a->id} tidak punya project - di-skip.");
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                $folderId = $folderCache[$project->id] ?? $project->drive_folder_id;
                if (! $folderId) {
                    $folderId = $drive->ensureFolder(
                        sprintf('[Project #%d] %s', $project->id, $project->name),
                    );
                    $project->update(['drive_folder_id' => $folderId]);
                }
                $folderCache[$project->id] = $folderId;

                $meta = $drive->upload($absolutePath, $a->original_name, $a->mime_type, $folderId);

                $a->update([
                    'storage_provider'    => 'google_drive',
                    'drive_file_id'       => $meta['id'],
                    'drive_web_view_link' => $meta['web_view_link'],
                    'drive_download_link' => $meta['download_link'],
                    'size_bytes'          => $meta['size'] ?: $a->size_bytes,
                ]);

                if (! $keep) {
                    Storage::disk('public')->delete($a->path);
                    $a->update(['path' => '']);
                }
                $ok++;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("Gagal migrasi #{$a->id}: " . $e->getMessage());
                $failed++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Selesai. Berhasil: $ok | Skip: $skipped | Gagal: $failed");
        if ($keep) {
            $this->warn('--keep aktif: file lokal tidak dihapus. Hapus manual setelah verifikasi.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function humanBytes(int $n): string
    {
        if ($n < 1024) return "$n B";
        if ($n < 1048576) return sprintf('%.1f KB', $n / 1024);
        if ($n < 1073741824) return sprintf('%.1f MB', $n / 1048576);
        return sprintf('%.1f GB', $n / 1073741824);
    }
}
