<?php

namespace App\Console\Commands;

use App\Models\ScheduledEmail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Flush scheduled_emails yang sudah due - kirim via JMAP Stalwart pakai
 * mailbox creds user yang sudah decrypt. Jalan tiap menit lewat scheduler.
 *
 * Strategy idempotent dengan `processing` lock:
 *   pending → processing (atomic update) → sent | failed
 * Max 3 attempts, exponential backoff antar retry (lewat increment attempt).
 */
class MailFlushScheduled extends Command
{
    protected $signature = 'mail:flush-scheduled {--limit=20 : maksimum email per run}';
    protected $description = 'Kirim scheduled emails yang sudah due via Stalwart JMAP';

    private const JMAP_BASE   = 'https://mail.ibereality.com';
    private const MAX_ATTEMPTS = 3;

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $due = ScheduledEmail::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        if ($due->isEmpty()) {
            $this->info('Tidak ada email due saat ini.');
            return self::SUCCESS;
        }

        $this->info("Memproses {$due->count()} email…");
        $sent = 0;
        $failed = 0;

        foreach ($due as $row) {
            // Atomic lock: cuma proses kalau berhasil set processing
            $updated = ScheduledEmail::where('id', $row->id)
                ->where('status', 'pending')
                ->update(['status' => 'processing', 'attempts' => $row->attempts + 1]);
            if (!$updated) continue;

            try {
                $this->dispatchOne($row);
                $row->update([
                    'status' => 'sent',
                    'processed_at' => now(),
                    'last_error' => null,
                ]);
                $sent++;
                $this->line("  ✓ #{$row->id} → {$row->mailbox_username}");
            } catch (\Throwable $e) {
                $failed++;
                // Last attempt? Mark failed permanently
                $finalAttempt = ($row->attempts + 1) >= self::MAX_ATTEMPTS;
                $row->update([
                    'status' => $finalAttempt ? 'failed' : 'pending',
                    'last_error' => substr($e->getMessage(), 0, 500),
                    'processed_at' => $finalAttempt ? now() : null,
                ]);
                $this->error("  ✗ #{$row->id} attempt {$row->attempts} - " . $e->getMessage());
            }
        }

        $this->info("Selesai · sent={$sent} failed={$failed}");
        return self::SUCCESS;
    }

    private function dispatchOne(ScheduledEmail $row): void
    {
        // Route by mailbox_username - user personal mailbox OR shared mailbox.
        // user_id sengaja gak dipakai supaya support shared mailbox case
        // (user_id NULL pas dijadwalkan dari shared).
        $password = null;
        $email = $row->mailbox_username;

        // Coba User dulu (paling umum)
        $user = User::withoutGlobalScopes()->where('email', $email)->first();
        if ($user && $user->mailbox_password_encrypted) {
            try {
                $password = Crypt::decryptString($user->mailbox_password_encrypted);
            } catch (\Throwable) {
                throw new \RuntimeException('User mailbox password decrypt error');
            }
        }

        // Fallback: shared mailbox
        if (!$password) {
            $shared = \App\Models\SharedMailbox::where('email', $email)
                ->where('is_active', true)
                ->first();
            if ($shared) {
                try {
                    $password = $shared->decryptPassword();
                } catch (\Throwable) {
                    throw new \RuntimeException('Shared mailbox password decrypt error');
                }
            }
        }

        if (!$password) {
            throw new \RuntimeException("Mailbox creds tidak ditemukan untuk: {$email}");
        }

        $payload = $row->payload;
        $this->sendViaJmap($email, $password, $payload);
    }

    private function sendViaJmap(string $email, string $password, array $payload): void
    {
        // Resolve JMAP session
        $sess = Http::withBasicAuth($email, $password)
            ->withHeaders(['Accept' => 'application/json'])
            ->get(self::JMAP_BASE . '/.well-known/jmap');
        if (!$sess->ok()) throw new \RuntimeException("JMAP session HTTP {$sess->status()}");

        $session = $sess->json();
        $accountId = $session['primaryAccounts']['urn:ietf:params:jmap:mail'] ?? null;
        $submissionAccountId = $session['primaryAccounts']['urn:ietf:params:jmap:submission'] ?? null;
        if (!$accountId || !$submissionAccountId) {
            throw new \RuntimeException('JMAP session tanpa primary account');
        }

        // Get Drafts + Sent mailbox IDs
        $mailRes = $this->jmapCall($email, $password, [
            'urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail',
        ], [['Mailbox/get', ['accountId' => $accountId], 'c0']]);
        $boxes = $mailRes['methodResponses'][0][1]['list'] ?? [];
        $drafts = collect($boxes)->firstWhere('role', 'drafts');
        $sent   = collect($boxes)->firstWhere('role', 'sent');
        if (!$drafts) throw new \RuntimeException('No Drafts mailbox');

        // Get default identity
        $idRes = $this->jmapCall($email, $password, [
            'urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:submission',
        ], [['Identity/get', ['accountId' => $submissionAccountId], 'c0']]);
        $identities = $idRes['methodResponses'][0][1]['list'] ?? [];
        $identity = collect($identities)->firstWhere('email', $email) ?? ($identities[0] ?? null);
        if (!$identity) throw new \RuntimeException('No sender identity');

        // Build Email/set + EmailSubmission/set (immediate, since we're already past sendAt)
        $bodyText = $payload['bodyText'] ?? '';
        $bodyHtml = $payload['bodyHtml'] ?? null;

        $bodyValues = ['t1' => ['value' => $bodyText, 'isEncodingProblem' => false, 'isTruncated' => false]];
        $textBody = [['partId' => 't1', 'type' => 'text/plain']];
        $htmlBody = [];
        if ($bodyHtml) {
            $bodyValues['h1'] = ['value' => $bodyHtml, 'isEncodingProblem' => false, 'isTruncated' => false];
            $htmlBody[] = ['partId' => 'h1', 'type' => 'text/html'];
        }
        $attachments = $payload['attachments'] ?? [];

        $createCall = [
            'mailboxIds' => [$drafts['id'] => true],
            'keywords'   => ['$draft' => true, '$seen' => true],
            'from'       => [['name' => strstr($email, '@', true), 'email' => $email]],
            'to'         => $payload['to'] ?? [],
            'subject'    => $payload['subject'] ?? '(no subject)',
            'bodyValues' => $bodyValues,
            'textBody'   => $textBody,
        ];
        if (!empty($payload['cc']))  $createCall['cc']  = $payload['cc'];
        if (!empty($payload['bcc'])) $createCall['bcc'] = $payload['bcc'];
        if (!empty($htmlBody))       $createCall['htmlBody']    = $htmlBody;
        if (!empty($attachments))    $createCall['attachments'] = $attachments;
        if (!empty($payload['inReplyTo']))  $createCall['inReplyTo']  = [$payload['inReplyTo']];
        if (!empty($payload['references'])) $createCall['references'] = $payload['references'];

        $submissionCreate = ['emailId' => '#d1', 'identityId' => $identity['id']];

        $calls = [
            ['Email/set', ['accountId' => $accountId, 'create' => ['d1' => $createCall]], 'c0'],
            ['EmailSubmission/set', [
                'accountId' => $submissionAccountId,
                'create' => ['s1' => $submissionCreate],
                'onSuccessUpdateEmail' => [
                    '#s1' => array_merge(
                        ["mailboxIds/{$drafts['id']}" => null],
                        $sent ? ["mailboxIds/{$sent['id']}" => true] : [],
                        ['keywords/$draft' => null],
                    ),
                ],
            ], 'c1'],
        ];

        $res = $this->jmapCall($email, $password, [
            'urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail', 'urn:ietf:params:jmap:submission',
        ], $calls);

        foreach ($res['methodResponses'] ?? [] as $r) {
            if (($r[0] ?? null) === 'error') {
                throw new \RuntimeException('JMAP error: ' . json_encode($r[1] ?? null));
            }
            $payload2 = $r[1] ?? [];
            if (!empty($payload2['notCreated'])) {
                throw new \RuntimeException('Send rejected: ' . json_encode($payload2['notCreated']));
            }
        }
    }

    private function jmapCall(string $email, string $password, array $using, array $methodCalls): array
    {
        $body = json_encode(['using' => $using, 'methodCalls' => $methodCalls], JSON_UNESCAPED_SLASHES);
        $res = Http::withBasicAuth($email, $password)
            ->withHeaders(['Accept' => 'application/json'])
            ->withBody($body, 'application/json')
            ->post(self::JMAP_BASE . '/jmap/');
        if (!$res->ok()) throw new \RuntimeException("JMAP HTTP {$res->status()}: " . $res->body());
        return $res->json();
    }
}
