<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StalwartMailClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

/**
 * Provision Stalwart mailboxes for existing employees (group=user) created
 * before the auto-provision feature went live.
 *
 * For each user:
 *   - Compute target email from name (first-name slug)
 *   - If user already has a non-stalwart email, just update users.email and
 *     create mailbox under new address
 *   - Store encrypted password back to users.mailbox_password_encrypted
 *   - Print [name, old_email, new_email, status] table
 *
 * Usage:
 *   php artisan mailboxes:backfill                      # dry-run (preview)
 *   php artisan mailboxes:backfill --execute            # actually create
 *   php artisan mailboxes:backfill --execute --user=ID  # single user
 *   php artisan mailboxes:backfill --execute --rotate   # rotate password even for existing mailboxes
 */
class MailboxBackfill extends Command
{
    protected $signature = 'mailboxes:backfill
        {--execute : Apply changes (default is dry-run)}
        {--user= : Backfill only the user with this id}
        {--rotate : Force password rotation even if mailbox already exists}';

    protected $description = 'Provision Stalwart mailboxes for existing employees (karyawan)';

    public function handle(StalwartMailClient $stalwart): int
    {
        $dryRun = !$this->option('execute');
        $rotate = (bool) $this->option('rotate');
        $userId = $this->option('user');

        $this->info($dryRun ? '🧪 DRY RUN - no changes will be applied. Use --execute to commit.' : '🚀 Executing backfill on production.');
        $this->newLine();

        $query = User::query()->withoutGlobalScopes()->where('group', 'user');
        if ($userId) $query->where('id', $userId);
        $users = $query->orderBy('name')->get();

        if ($users->isEmpty()) {
            $this->warn('No karyawan (group=user) found.');
            return Command::SUCCESS;
        }

        $rows = [];
        $domain = config('services.stalwart.domain', 'ibereality.com');

        foreach ($users as $user) {
            $localPart = $stalwart->slugifyFirstName($user->name);
            if (!$localPart) {
                $rows[] = [$user->id, $user->name, $user->email ?? '-', '⏭ skip', 'cannot derive name'];
                continue;
            }

            $targetEmail = "{$localPart}@{$domain}";
            $existingId = $stalwart->findAccountByName($localPart);
            $oldEmail = $user->email ?? '-';

            if ($dryRun) {
                $action = ($existingId !== null)
                    ? ($rotate ? '🔁 rotate password' : '✓ already exists')
                    : '➕ would create';
                $rows[] = [$user->id, $user->name, $oldEmail, $targetEmail, $action];
                continue;
            }

            // Execute mode

            // ── Rotate path: if user already has @ibereality.com mailbox, just
            // generate a new password for that EXISTING Stalwart account.
            if ($rotate && $user->email && str_ends_with($user->email, '@ibereality.com')) {
                $existingLocalPart = strtok($user->email, '@');
                $accountId = $stalwart->findAccountByName($existingLocalPart);
                if ($accountId !== null) {
                    $newPassword = $stalwart->generatePassword();
                    $ok = $stalwart->updatePassword($accountId, $newPassword);
                    if ($ok) {
                        $user->update(['mailbox_password_encrypted' => Crypt::encryptString($newPassword)]);
                        $rows[] = [$user->id, $user->name, $oldEmail, $user->email, '🔁 password rotated'];
                        continue;
                    }
                }
                $rows[] = [$user->id, $user->name, $oldEmail, $user->email, '❌ rotate FAILED'];
                continue;
            }

            // ── Align path: existing Stalwart account, no rotation requested.
            $alreadyTakenByOtherUser = User::query()->withoutGlobalScopes()
                ->where('email', $targetEmail)
                ->where('id', '!=', $user->id)
                ->exists();

            if ($existingId !== null && !$alreadyTakenByOtherUser) {
                if ($user->email !== $targetEmail) {
                    $user->update(['email' => $targetEmail]);
                }
                $rows[] = [$user->id, $user->name, $oldEmail, $targetEmail, '✓ aligned (existing)'];
                continue;
            }

            // ── Provision path: new mailbox needed.
            $creds = $stalwart->provisionForUser($user);
            if ($creds) {
                $user->update([
                    'email'                       => $creds['email'],
                    'mailbox_password_encrypted'  => Crypt::encryptString($creds['password']),
                ]);
                $rows[] = [$user->id, $user->name, $oldEmail, $creds['email'], '✅ provisioned'];
            } else {
                $rows[] = [$user->id, $user->name, $oldEmail, $targetEmail, '❌ FAILED (check log)'];
            }
        }

        $this->table(['User ID', 'Name', 'Old Email', 'Target Email', 'Status'], $rows);

        $this->newLine();
        $this->info("Total: {$users->count()} karyawan processed");
        if ($dryRun) {
            $this->comment('Re-run with --execute to apply changes.');
        }

        return Command::SUCCESS;
    }
}
