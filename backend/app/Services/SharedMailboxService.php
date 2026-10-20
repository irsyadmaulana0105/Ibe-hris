<?php

namespace App\Services;

use App\Models\SharedMailbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Shared mailbox switching: superadmin (atau group lain di allowed_groups)
 * dapat one-click switch ke akun email bersama tanpa share password.
 *
 * Flow:
 *   1. Frontend GET /auth/shared-mailboxes → list yang accessible utk caller
 *   2. Frontend POST /auth/shared-mailbox-ticket {shared_mailbox_id}
 *      → service generate one-time ticket di webmail_sso_tickets dgn
 *        shared_mailbox_id terisi
 *   3. Frontend window.location.replace(redirect_url)
 *   4. Backend redeem ticket → decrypt password → render HTML auto-login
 *      ke webmail (sama pattern dgn user-mailbox redirect)
 */
class SharedMailboxService
{
    public function listForUser(?User $user): array
    {
        if (!$user) return [];
        return SharedMailbox::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (SharedMailbox $m) => $m->isAccessibleBy($user))
            ->map(fn (SharedMailbox $m) => [
                'id'           => $m->id,
                'email'        => $m->email,
                'display_name' => $m->display_name,
                'description'  => $m->description,
                'last_switched_at' => $m->last_switched_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function generateTicket(User $caller, int $sharedMailboxId): array
    {
        $mailbox = SharedMailbox::query()->where('is_active', true)->findOrFail($sharedMailboxId);
        if (!$mailbox->isAccessibleBy($caller)) {
            throw new \RuntimeException('Anda tidak punya akses ke mailbox ini.');
        }

        $ticket = bin2hex(random_bytes(24));
        DB::table('webmail_sso_tickets')->insert([
            'ticket'             => $ticket,
            'user_id'            => $caller->id,   // siapa yang trigger (audit)
            'shared_mailbox_id'  => $mailbox->id,
            'expires_at'         => now()->addSeconds(60),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        return [
            'redirect_url' => url("/api/v1/auth/shared-mailbox-redirect?ticket={$ticket}"),
            'expires_in'   => 60,
            'email'        => $mailbox->email,
        ];
    }

    /**
     * Redeem ticket, return decrypted credentials. Marks ticket as used.
     * Updates last_switched_at/by on mailbox.
     *
     * @return array{mailbox: SharedMailbox, password: string, caller: User|null}|null
     */
    public function redeemTicket(string $ticket): ?array
    {
        $row = DB::table('webmail_sso_tickets')
            ->where('ticket', $ticket)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->whereNotNull('shared_mailbox_id')
            ->first();
        if (!$row) return null;

        DB::table('webmail_sso_tickets')->where('id', $row->id)->update(['used_at' => now()]);

        $mailbox = SharedMailbox::query()->find($row->shared_mailbox_id);
        if (!$mailbox || !$mailbox->is_active) return null;

        $caller = User::query()->withoutGlobalScopes()->find($row->user_id);

        $mailbox->update([
            'last_switched_at' => now(),
            'last_switched_by' => $caller?->id,
        ]);

        try {
            $password = $mailbox->decryptPassword();
        } catch (\Throwable $e) {
            return null;
        }

        return ['mailbox' => $mailbox, 'password' => $password, 'caller' => $caller];
    }
}
