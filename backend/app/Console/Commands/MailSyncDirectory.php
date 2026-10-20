<?php

namespace App\Console\Commands;

use App\Models\SharedMailbox;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Push IBE Reality directory ke address book setiap user di Stalwart via JMAP.
 *
 * Strategi: per-user push (no shared address book sharing complexity).
 * Setiap user dapat address book "IBE Reality Directory" di akun mereka
 * sendiri, berisi semua user @ibereality.com lain + shared mailboxes.
 * Webmail query via standard JMAP ContactCard/query terhadap address book
 * tersebut - sama persis pattern Email/query.
 *
 * Idempotent - diff vs existing contact set; upsert by email match.
 */
class MailSyncDirectory extends Command
{
    protected $signature = 'mail:sync-directory {--dry-run} {--user= : Sync hanya untuk email user ini}';

    protected $description = 'Sync IBE user directory ke address book Stalwart untuk autocomplete webmail';

    private const ADDR_BOOK_NAME = 'IBE Reality Directory';
    private const JMAP_BASE      = 'https://mail.ibereality.com';

    public function handle(): int
    {
        $contacts = $this->buildDirectory();
        if (empty($contacts)) {
            $this->error('Direktori kosong - tidak ada user @ibereality.com untuk di-sync.');
            return self::FAILURE;
        }

        $this->info('Direktori sumber: ' . count($contacts) . ' kontak');

        $usersQuery = User::withoutGlobalScopes()
            ->where('email', 'like', '%@ibereality.com')
            ->whereNotNull('mailbox_password_encrypted');
        if ($single = $this->option('user')) {
            $usersQuery->where('email', $single);
        }
        $users = $usersQuery->get(['id', 'email', 'name', 'mailbox_password_encrypted']);

        $this->info('Target sync: ' . $users->count() . ' user mailbox');
        if ($this->option('dry-run')) {
            $this->warn('DRY RUN - tidak ada perubahan dikirim ke Stalwart.');
        }

        $totalCreated = 0;
        $totalUpdated = 0;
        $totalRemoved = 0;
        $errors = 0;

        foreach ($users as $u) {
            try {
                $pass = Crypt::decryptString($u->mailbox_password_encrypted);
            } catch (\Throwable $e) {
                $this->error("  ✗ {$u->email} - mailbox password decrypt error");
                $errors++;
                continue;
            }

            try {
                [$created, $updated, $removed] = $this->syncOne($u, $pass, $contacts);
                $totalCreated += $created;
                $totalUpdated += $updated;
                $totalRemoved += $removed;
                $this->line(sprintf(
                    '  %s %s - +%d ~%d -%d',
                    $this->option('dry-run') ? '·' : '✓',
                    $u->email,
                    $created,
                    $updated,
                    $removed,
                ));
            } catch (\Throwable $e) {
                $this->error("  ✗ {$u->email} - " . $e->getMessage());
                $errors++;
            }
        }

        $this->info(sprintf(
            'Selesai · created=%d updated=%d removed=%d errors=%d',
            $totalCreated, $totalUpdated, $totalRemoved, $errors,
        ));
        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * IBE Reality directory dengan metadata kategorisasi:
     *   - category: "Manajemen" / "Divisi <X>" / "Karyawan Umum" / "Shared Inbox"
     *   - division: nama divisi (kalau ada)
     *   - title: nama jabatan (kalau ada)
     * Dikirim sebagai organizations + titles + keywords di ContactCard JSContact.
     */
    private function buildDirectory(): array
    {
        $contacts = [];
        $rows = User::withoutGlobalScopes()
            ->where('email', 'like', '%@ibereality.com')
            ->whereNotNull('email')
            ->with(['division', 'jobTitle'])
            ->orderBy('name')
            ->get();
        foreach ($rows as $r) {
            if (!$r->email) continue;
            $division = $r->division?->name;
            $isManagement = in_array($r->group, ['superadmin', 'admin', 'finance'], true);
            if ($isManagement) {
                $category = 'Manajemen';
            } elseif ($division) {
                $category = $division; // "Divisi Produksi" dll. - pakai langsung sbg category
            } else {
                $category = 'Karyawan Umum';
            }
            $contacts[strtolower($r->email)] = [
                'email'    => $r->email,
                'name'     => $r->name ?: strtok($r->email, '@'),
                'category' => $category,
                'division' => $division,
                'title'    => $r->jobTitle?->name,
            ];
        }
        foreach (SharedMailbox::query()->where('is_active', true)->get() as $m) {
            $contacts[strtolower($m->email)] = [
                'email'    => $m->email,
                'name'     => $m->display_name,
                'category' => 'Shared Inbox',
                'division' => null,
                'title'    => null,
            ];
        }
        return array_values($contacts);
    }

    /**
     * Sync untuk satu user: pastikan address book "IBE Reality Directory" ada,
     * lalu upsert contacts (exclude self). Return [created, updated, removed].
     */
    private function syncOne(User $user, string $password, array $directoryContacts): array
    {
        $dry = (bool) $this->option('dry-run');

        $session = $this->jmapSession($user->email, $password);
        $accountId = $session['primaryAccounts']['urn:ietf:params:jmap:contacts'] ?? null;
        if (!$accountId) {
            throw new \RuntimeException('JMAP session tanpa primary contacts account');
        }

        // 1. Ensure address book
        $bookId = $this->ensureAddressBook($user->email, $password, $accountId, $dry);
        if ($dry && !$bookId) $bookId = 'DRY';

        // 2. List existing contacts in this book
        $existing = $this->listContacts($user->email, $password, $accountId, $bookId);
        $existingByEmail = [];
        foreach ($existing as $c) {
            $primaryEmail = null;
            foreach (($c['emails'] ?? []) as $e) {
                if (!empty($e['address'])) { $primaryEmail = strtolower($e['address']); break; }
            }
            if ($primaryEmail) $existingByEmail[$primaryEmail] = $c;
        }

        // 3. Compute diffs (skip self)
        $selfLower = strtolower($user->email);
        $wantByEmail = [];
        foreach ($directoryContacts as $c) {
            $k = strtolower($c['email']);
            if ($k === $selfLower) continue;
            $wantByEmail[$k] = $c;
        }

        $toCreate = [];
        $toUpdate = [];
        foreach ($wantByEmail as $k => $want) {
            if (!isset($existingByEmail[$k])) {
                $toCreate[$k] = $want;
            } else {
                $cur = $existingByEmail[$k];
                if ($this->differs($cur, $want)) {
                    $toUpdate[$cur['id']] = $want;
                }
            }
        }
        $toDestroy = [];
        foreach ($existingByEmail as $k => $cur) {
            if (!isset($wantByEmail[$k])) $toDestroy[] = $cur['id'];
        }

        if ($dry) {
            return [count($toCreate), count($toUpdate), count($toDestroy)];
        }

        // 4. Apply changes via ContactCard/set (single call, idempotent)
        if (!empty($toCreate) || !empty($toUpdate) || !empty($toDestroy)) {
            $createPayload = [];
            $i = 0;
            foreach ($toCreate as $c) {
                $createPayload['c' . ($i++)] = $this->buildCardPayload($c, $bookId);
            }
            $updatePayload = [];
            foreach ($toUpdate as $id => $c) {
                $updatePayload[$id] = $this->buildCardPayload($c, null);
            }
            $args = ['accountId' => $accountId];
            if ($createPayload)  $args['create']  = $createPayload;
            if ($updatePayload)  $args['update']  = $updatePayload;
            if ($toDestroy)      $args['destroy'] = $toDestroy;
            $this->jmapCall($user->email, $password,
                ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:contacts'],
                [['ContactCard/set', $args, 'c0']],
            );
        }

        return [count($toCreate), count($toUpdate), count($toDestroy)];
    }

    /**
     * Build ContactCard JSContact payload dengan kategorisasi.
     * `addressBookIds` cuma di-include saat create (update gak boleh nyentuh).
     */
    private function buildCardPayload(array $c, ?string $bookId): array
    {
        $payload = [
            'name'   => ['full' => $c['name']],
            'emails' => ['e1' => ['address' => $c['email']]],
            // keywords boolean map - dipakai webmail untuk grouping di dropdown
            'keywords' => [$c['category'] => true],
        ];
        if ($bookId !== null) {
            $payload['addressBookIds'] = [$bookId => true];
        }
        // organizations: divisi sebagai org unit
        if (!empty($c['division'])) {
            $payload['organizations'] = [
                'o1' => [
                    'name' => 'IBE Reality',
                    'units' => [['name' => $c['division']]],
                ],
            ];
        }
        // titles: jabatan
        if (!empty($c['title'])) {
            $payload['titles'] = [
                't1' => ['name' => $c['title'], 'kind' => 'title'],
            ];
        }
        return $payload;
    }

    /** Bandingkan ContactCard remote vs target untuk decide update vs skip. */
    private function differs(array $cur, array $want): bool
    {
        if (($cur['name']['full'] ?? '') !== $want['name']) return true;
        $curKeywords = array_keys($cur['keywords'] ?? []);
        if (!in_array($want['category'], $curKeywords, true)) return true;
        $curDiv = $cur['organizations']['o1']['units'][0]['name']
            ?? ($cur['organizations'][array_key_first($cur['organizations'] ?? [])]['units'][0]['name'] ?? null);
        if (($want['division'] ?? null) !== $curDiv) return true;
        $curTitle = $cur['titles']['t1']['name']
            ?? ($cur['titles'][array_key_first($cur['titles'] ?? [])]['name'] ?? null);
        if (($want['title'] ?? null) !== $curTitle) return true;
        return false;
    }

    private function ensureAddressBook(string $email, string $password, string $accountId, bool $dry): ?string
    {
        $res = $this->jmapCall($email, $password,
            ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:contacts'],
            [['AddressBook/get', ['accountId' => $accountId], 'c0']],
        );
        $books = $res['methodResponses'][0][1]['list'] ?? [];
        foreach ($books as $b) {
            if (($b['name'] ?? '') === self::ADDR_BOOK_NAME) return $b['id'];
        }
        if ($dry) return null;

        $res = $this->jmapCall($email, $password,
            ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:contacts'],
            [['AddressBook/set', [
                'accountId' => $accountId,
                'create'    => [
                    'ab1' => [
                        'name'        => self::ADDR_BOOK_NAME,
                        'description' => 'Direktori karyawan IBE Reality (di-sync otomatis)',
                        'sortOrder'   => 1,
                    ],
                ],
            ], 'c0']],
        );
        $created = $res['methodResponses'][0][1]['created']['ab1'] ?? null;
        if (!$created) {
            throw new \RuntimeException('AddressBook/set create failed: ' . json_encode($res['methodResponses'][0][1] ?? null));
        }
        return $created['id'];
    }

    private function listContacts(string $email, string $password, string $accountId, string $bookId): array
    {
        // Stalwart belum support filter inAddressBook - query semua, filter di PHP.
        // Untuk akun mailbox normal jumlah kontaknya kecil (< 100), masih affordable.
        $res = $this->jmapCall($email, $password,
            ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:contacts'],
            [
                ['ContactCard/query', ['accountId' => $accountId, 'limit' => 500], 'q'],
                ['ContactCard/get', [
                    'accountId' => $accountId,
                    '#ids' => ['resultOf' => 'q', 'name' => 'ContactCard/query', 'path' => '/ids'],
                ], 'g'],
            ],
        );
        $list = $res['methodResponses'][1][1]['list'] ?? [];
        return array_values(array_filter($list, fn ($c) => isset($c['addressBookIds'][$bookId])));
    }

    private function jmapSession(string $email, string $password): array
    {
        $res = Http::withBasicAuth($email, $password)
            ->withHeaders(['Accept' => 'application/json'])
            ->get(self::JMAP_BASE . '/.well-known/jmap');
        if (!$res->ok()) {
            throw new \RuntimeException("JMAP session HTTP {$res->status()} untuk {$email}");
        }
        return $res->json();
    }

    private function jmapCall(string $email, string $password, array $using, array $methodCalls): array
    {
        // JSON_UNESCAPED_SLASHES penting: Stalwart parser strict, ESCAPE "/" jadi "\/"
        // di method name (e.g. "AddressBook\/get") trigger error notRequest. cURL manual
        // tidak escape jadi diterima - Laravel default-nya escape.
        $body = json_encode([
            'using'       => $using,
            'methodCalls' => $methodCalls,
        ], JSON_UNESCAPED_SLASHES);

        $res = Http::withBasicAuth($email, $password)
            ->withHeaders(['Accept' => 'application/json'])
            ->withBody($body, 'application/json')
            ->post(self::JMAP_BASE . '/jmap/');
        if (!$res->ok()) {
            throw new \RuntimeException("JMAP call HTTP {$res->status()} untuk {$email}: " . $res->body());
        }
        $data = $res->json();
        foreach ($data['methodResponses'] ?? [] as $r) {
            if (($r[0] ?? null) === 'error') {
                throw new \RuntimeException('JMAP error: ' . json_encode($r[1] ?? null));
            }
        }
        return $data;
    }
}
