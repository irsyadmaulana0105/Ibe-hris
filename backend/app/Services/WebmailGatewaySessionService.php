<?php

namespace App\Services;

use App\Models\SharedMailbox;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WebmailGatewaySessionService
{
    public function redeem(string $plainTicket, Request $request): array
    {
        return DB::transaction(function () use ($plainTicket, $request) {
            $ticket = DB::table('webmail_sso_tickets')
                ->where('ticket', hash('sha256', $plainTicket))
                ->whereNull('used_at')->where('expires_at', '>', now())
                ->lockForUpdate()->first();

            if (! $ticket) {
                throw new HttpException(410, 'Ticket invalid atau kadaluwarsa.');
            }

            DB::table('webmail_sso_tickets')->where('id', $ticket->id)->update(['used_at' => now()]);
            $user = User::query()->withoutGlobalScopes()->find($ticket->user_id);
            if (! $user?->email || ! $user->mailbox_password_encrypted) {
                throw new HttpException(422, 'Mailbox tidak tersedia.');
            }

            $plainSession = bin2hex(random_bytes(32));
            DB::table('webmail_gateway_sessions')->insert([
                'token_hash' => hash('sha256', $plainSession),
                'user_id' => $user->id,
                'mailbox_email' => $user->email,
                'expires_at' => now()->addMinutes(config('webmail_gateway.session_ttl_minutes')),
                'ip_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
                'user_agent_hash' => $request->userAgent() ? hash('sha256', $request->userAgent()) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$plainSession, $user];
        });
    }

    public function resolve(Request $request): ?object
    {
        $plain = $request->cookie(config('webmail_gateway.cookie'));
        if (! is_string($plain) || strlen($plain) !== 64) return null;

        $session = DB::table('webmail_gateway_sessions')
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        if ($session) {
            $user = User::query()->withoutGlobalScopes()->find($session->user_id);
            if (! $user || $user->is_inactive) {
                DB::table('webmail_gateway_sessions')->where('id', $session->id)
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);
                return null;
            }
            DB::table('webmail_gateway_sessions')->where('id', $session->id)
                ->update(['last_used_at' => now(), 'updated_at' => now()]);
        }
        return $session;
    }

    public function upstreamAuthorization(object $session): string
    {
        $user = User::query()->withoutGlobalScopes()->find($session->user_id);
        if (! $user) {
            throw new HttpException(401, 'Mailbox session tidak valid.');
        }

        $email = strtolower((string) $session->mailbox_email);
        if ($email === strtolower((string) $user->email) && $user->mailbox_password_encrypted) {
            $password = Crypt::decryptString($user->mailbox_password_encrypted);
        } else {
            $mailbox = SharedMailbox::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if (! $mailbox?->isAccessibleBy($user)) {
                throw new HttpException(401, 'Mailbox session tidak valid.');
            }
            $password = $mailbox->decryptPassword();
        }

        return 'Basic '.base64_encode($email.':'.$password);
    }

    public function availableMailboxes(object $session): array
    {
        $user = User::query()->withoutGlobalScopes()->find($session->user_id);
        if (! $user) {
            return [];
        }

        $mailboxes = [[
            'email' => $user->email,
            'display_name' => $user->name ?: strtok($user->email, '@'),
            'shared' => false,
        ]];

        SharedMailbox::query()->where('is_active', true)->get()
            ->filter(fn (SharedMailbox $mailbox) => $mailbox->isAccessibleBy($user))
            ->each(function (SharedMailbox $mailbox) use (&$mailboxes) {
                $mailboxes[] = [
                    'email' => $mailbox->email,
                    'display_name' => $mailbox->display_name,
                    'shared' => true,
                ];
            });

        return $mailboxes;
    }

    public function switchMailbox(Request $request, string $email): object
    {
        $session = $this->resolve($request);
        if (! $session) {
            throw new HttpException(401, 'Webmail session tidak aktif.');
        }

        $email = strtolower(trim($email));
        $allowed = collect($this->availableMailboxes($session))
            ->contains(fn (array $mailbox) => strtolower($mailbox['email']) === $email);
        if (! $allowed) {
            throw new HttpException(403, 'Anda tidak memiliki akses ke mailbox tersebut.');
        }

        DB::table('webmail_gateway_sessions')->where('id', $session->id)->update([
            'mailbox_email' => $email,
            'last_used_at' => now(),
            'updated_at' => now(),
        ]);
        $session->mailbox_email = $email;

        return $session;
    }

    public function revoke(Request $request): void
    {
        $plain = $request->cookie(config('webmail_gateway.cookie'));
        if (is_string($plain)) {
            DB::table('webmail_gateway_sessions')->where('token_hash', hash('sha256', $plain))
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
        }
    }
}
