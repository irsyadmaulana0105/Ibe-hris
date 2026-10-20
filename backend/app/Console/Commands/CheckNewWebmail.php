<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Check inbox per user yang punya active web push subscription.
 * Kalau ada email baru sejak last check → kirim push notification.
 *
 * Run tiap 1 menit via scheduler.
 *
 * Performance:
 *   - Hanya cek user yang punya >= 1 subscription aktif (push_failures < threshold)
 *   - Pakai JMAP /api/session + Email/query (sortir receivedAt desc, limit 1)
 *   - Compare id terbaru vs last_seen_email_id di webmail_inbox_state
 *   - Kalau berbeda → email baru → kirim push
 *
 * Authentication ke Stalwart pakai user.mailbox_password_encrypted (decrypted).
 * Tidak ada akses Stalwart admin - pure per-user JMAP request.
 */
class CheckNewWebmail extends Command
{
    protected $signature = 'webmail:check-new-mail
                            {--user= : Cek user tertentu saja (email atau id)}
                            {--dry-run : Detect tapi jangan kirim push}';

    protected $description = 'Poll inbox per user yang punya push subscription, kirim notifikasi kalau ada email baru';

    private string $stalwartBaseUrl;

    public function handle(): int
    {
        $this->stalwartBaseUrl = config('webmail.stalwart_url', 'http://127.0.0.1:8080');

        // Find users with active subscriptions
        $query = User::query()
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('web_push_subscriptions')
                  ->whereColumn('web_push_subscriptions.user_id', 'users.id')
                  ->where('push_failures', '<', config('webpush.max_failures', 5));
            })
            ->whereNotNull('mailbox_password_encrypted')
            ->whereNotNull('email');

        if ($userFilter = $this->option('user')) {
            $query->where(function ($q) use ($userFilter) {
                $q->where('email', $userFilter)->orWhere('id', $userFilter);
            });
        }

        $users = $query->get();
        $this->info("Checking {$users->count()} users for new mail...");

        $totalPushed = 0;
        foreach ($users as $user) {
            try {
                $pushed = $this->checkUser($user);
                $totalPushed += $pushed;
            } catch (\Throwable $e) {
                Log::warning("webmail check failed for {$user->email}: " . $e->getMessage());
            }
        }

        $this->info("Done. Pushed {$totalPushed} notifications.");
        return self::SUCCESS;
    }

    private function checkUser(User $user): int
    {
        $password = Crypt::decryptString($user->mailbox_password_encrypted);
        $authHeader = 'Basic ' . base64_encode("{$user->email}:{$password}");

        // 1. Session - get accountId + apiUrl
        $session = Http::withHeaders(['Authorization' => $authHeader])
            ->timeout(10)
            ->get("{$this->stalwartBaseUrl}/jmap/session");
        if (!$session->ok()) {
            $this->line("  ✗ {$user->email}: session failed " . $session->status());
            return 0;
        }
        $sessionData = $session->json();
        $accountId = $sessionData['primaryAccounts']['urn:ietf:params:jmap:mail'] ?? null;
        $apiUrl = $sessionData['apiUrl'] ?? "{$this->stalwartBaseUrl}/jmap";
        if (!$accountId) return 0;

        // 2. Find inbox mailbox id
        $mailboxRes = Http::withHeaders([
            'Authorization' => $authHeader,
            'Content-Type'  => 'application/json',
        ])->timeout(10)->post($apiUrl, [
            'using'        => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'],
            'methodCalls'  => [
                ['Mailbox/query', ['accountId' => $accountId, 'filter' => ['role' => 'inbox']], 'a'],
            ],
        ]);
        if (!$mailboxRes->ok()) return 0;
        $mboxIds = $mailboxRes->json('methodResponses.0.1.ids', []);
        $inboxId = $mboxIds[0] ?? null;
        if (!$inboxId) return 0;

        // 3. Query newest email in inbox
        $emailRes = Http::withHeaders([
            'Authorization' => $authHeader,
            'Content-Type'  => 'application/json',
        ])->timeout(15)->post($apiUrl, [
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'],
            'methodCalls' => [
                ['Email/query', [
                    'accountId' => $accountId,
                    'filter'    => ['inMailbox' => $inboxId],
                    'sort'      => [['property' => 'receivedAt', 'isAscending' => false]],
                    'limit'     => 5,
                ], 'q'],
                ['Email/get', [
                    'accountId'  => $accountId,
                    '#ids'       => ['name' => 'Email/query', 'path' => '/ids', 'resultOf' => 'q'],
                    'properties' => ['id', 'from', 'subject', 'receivedAt', 'preview'],
                ], 'g'],
            ],
        ]);
        if (!$emailRes->ok()) return 0;
        $emails = $emailRes->json('methodResponses.1.1.list', []);
        if (empty($emails)) return 0;

        $newestId = $emails[0]['id'];

        // 4. Compare to last_seen
        $state = DB::table('webmail_inbox_state')->where('user_id', $user->id)->first();
        $lastSeen = $state->last_seen_email_id ?? null;

        // First run - establish baseline silently
        if ($lastSeen === null) {
            DB::table('webmail_inbox_state')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'last_seen_email_id' => $newestId,
                    'last_checked_at'    => now(),
                    'updated_at'         => now(),
                    'created_at'         => now(),
                ],
            );
            return 0;
        }

        if ($newestId === $lastSeen) {
            DB::table('webmail_inbox_state')->where('user_id', $user->id)->update([
                'last_checked_at' => now(),
                'updated_at'      => now(),
            ]);
            return 0;
        }

        // Find new emails (above last_seen)
        $newEmails = [];
        foreach ($emails as $em) {
            if ($em['id'] === $lastSeen) break;
            $newEmails[] = $em;
        }
        if (empty($newEmails)) {
            // last_seen tidak ketemu di 5 terbaru → skip detail, tetap update state
            $newEmails = [$emails[0]];
        }

        if ($this->option('dry-run')) {
            $this->line("  📧 {$user->email}: " . count($newEmails) . ' new (DRY RUN)');
            return 0;
        }

        // 5. Send push to all subs for this user
        $pushed = $this->sendPushToUser($user, $newEmails);

        // 6. Update last_seen
        DB::table('webmail_inbox_state')->where('user_id', $user->id)->update([
            'last_seen_email_id' => $newestId,
            'last_checked_at'    => now(),
            'updated_at'         => now(),
        ]);

        $this->line("  📨 {$user->email}: " . count($newEmails) . " new → {$pushed} pushed");
        return $pushed;
    }

    private function sendPushToUser(User $user, array $emails): int
    {
        $subs = WebPushSubscription::where('user_id', $user->id)
            ->where('push_failures', '<', config('webpush.max_failures', 5))
            ->get();
        if ($subs->isEmpty()) return 0;

        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => config('webpush.vapid.subject'),
                'publicKey'  => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ]);

        // Payload - sender + subject + count
        $first = $emails[0];
        $fromName = $first['from'][0]['name'] ?? $first['from'][0]['email'] ?? 'IBE Mail';
        $title = count($emails) === 1 ? $fromName : count($emails) . ' email baru';
        $body = count($emails) === 1
            ? ($first['subject'] ?? '(no subject)')
            : $fromName . ': ' . ($first['subject'] ?? '(no subject)');

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => '/mail/inbox/' . $first['id'],
            'tag'   => 'ibe-mail-new',
        ]);

        foreach ($subs as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint'        => $sub->endpoint,
                    'publicKey'       => $sub->p256dh,
                    'authToken'       => $sub->auth,
                    'contentEncoding' => 'aes128gcm',
                ]),
                $payload,
            );
        }

        $pushed = 0;
        foreach ($webPush->flush() as $report) {
            $subEndpoint = $report->getRequest()->getUri()->__toString();
            $subRow = $subs->first(fn ($s) => $s->endpoint === $subEndpoint);
            if (!$subRow) continue;

            if ($report->isSuccess()) {
                $subRow->update([
                    'last_pushed_at' => now(),
                    'push_failures'  => 0,
                ]);
                $pushed++;
            } else {
                // Endpoint expired/gone → 410, 404 → delete sub
                $statusCode = $report->getResponse()?->getStatusCode();
                if (in_array($statusCode, [404, 410], true)) {
                    $subRow->delete();
                } else {
                    $subRow->increment('push_failures');
                }
            }
        }

        return $pushed;
    }
}
