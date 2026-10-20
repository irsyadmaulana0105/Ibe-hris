<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Provisions Stalwart Mail mailboxes via the official `stalwart-cli` binary
 * installed at /home/ibe/.cargo/bin/stalwart-cli.
 *
 * The CLI wraps the management JSON-RPC API; we invoke it via Symfony Process
 * for proper arg escaping and stderr capture.
 *
 * Workflow:
 *   - provisionForUser($user): create mailbox + return [email, password]
 *   - deprovision($email):     delete mailbox (called on resignation)
 *   - findAccountByName($lp):  lookup Stalwart account id by local-part
 *
 * Strong-password requirement: Stalwart rejects weak passwords. Our generator
 * mixes 16 chars across alphabet/digits/symbols to clear the entropy gate.
 */
class StalwartMailClient
{
    private ?string $domainIdCache = null;

    /**
     * @return array{email:string, password:string}|null
     */
    public function provisionForUser(User $user): ?array
    {
        $domain = config('services.stalwart.domain', 'ibereality.com');
        $localPart = $this->slugifyFirstName($user->name);
        if (!$localPart) {
            Log::warning("Stalwart: cannot derive local-part from name: {$user->name}");
            return null;
        }

        $email = $this->findUniqueAddress($localPart, $domain);
        $localPartUnique = strtok($email, '@');
        $password = $this->generatePassword();

        $domainId = $this->getDomainId($domain);
        if (!$domainId) {
            Log::warning("Stalwart: domain {$domain} not found");
            return null;
        }

        // If account already exists, rotate password & reuse
        $existingId = $this->findAccountByName($localPartUnique);
        if ($existingId !== null) {
            Log::info("Stalwart: account {$email} already exists (id={$existingId}); rotating password");
            $this->updatePassword($existingId, $password);
            return ['email' => $email, 'password' => $password];
        }

        $payload = [
            'name'        => $localPartUnique,
            'domainId'    => $domainId,
            'description' => $user->name,
            // Stalwart expects credentials as object {"0": {...}}, not JSON array.
            // PHP json_encode treats numeric-string keys as array → use object cast.
            'credentials' => (object) [
                '0' => [
                    '@type'      => 'Password',
                    'secret'     => $password,
                    'allowedIps' => (object) [],
                ],
            ],
        ];

        $result = $this->cli(['create', 'Account/User', '--json', json_encode($payload)]);
        if (!$result['ok']) {
            Log::warning("Stalwart provision failed: {$result['error']}");
            return null;
        }

        Log::info("Stalwart mailbox provisioned: {$email}");
        return ['email' => $email, 'password' => $password];
    }

    public function deprovision(string $email): bool
    {
        $localPart = strtok($email, '@');
        $id = $this->findAccountByName($localPart);
        if ($id === null) {
            Log::info("Stalwart deprovision: no mailbox for {$email} (already deleted?)");
            return true;
        }

        $result = $this->cli(['delete', 'Account', '--ids', $id]);
        if ($result['ok']) {
            Log::info("Stalwart mailbox deleted: {$email} (id={$id})");
            return true;
        }
        Log::warning("Stalwart deprovision failed for {$email}: {$result['error']}");
        return false;
    }

    public function findAccountByName(string $localPart): ?string
    {
        $result = $this->cli(['query', 'Account']);
        if (!$result['ok']) return null;

        foreach (preg_split('/\R/', trim($result['stdout'])) as $line) {
            if (preg_match('/^([a-z0-9]+)\s+' . preg_quote($localPart, '/') . '@/i', $line, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    public function updatePassword(string $accountId, string $newPassword): bool
    {
        $payload = [
            'credentials' => (object) [
                '0' => [
                    '@type'      => 'Password',
                    'secret'     => $newPassword,
                    'allowedIps' => (object) [],
                ],
            ],
        ];

        $result = $this->cli(['update', 'Account', $accountId, '--json', json_encode($payload)]);
        return $result['ok'];
    }

    public function slugifyFirstName(string $name): string
    {
        $honorifics = ['dr', 'drs', 'ir', 'mr', 'mrs', 'ms', 'h', 'hj'];
        $parts = preg_split('/\s+/', trim($name));
        $first = null;
        foreach ($parts as $p) {
            $lower = strtolower(rtrim($p, '.'));
            if (in_array($lower, $honorifics, true)) continue;
            $first = $lower;
            break;
        }
        if (!$first) return '';
        return Str::slug(Str::ascii($first), '');
    }

    private function findUniqueAddress(string $localPart, string $domain): string
    {
        $candidate = "{$localPart}@{$domain}";
        if (!$this->isEmailTaken($candidate)) return $candidate;

        for ($i = 2; $i <= 99; $i++) {
            $candidate = "{$localPart}{$i}@{$domain}";
            if (!$this->isEmailTaken($candidate)) return $candidate;
        }
        return "{$localPart}-" . uniqid() . "@{$domain}";
    }

    private function isEmailTaken(string $email): bool
    {
        if (User::query()->withoutGlobalScopes()->where('email', $email)->exists()) {
            return true;
        }
        $localPart = strtok($email, '@');
        return $this->findAccountByName($localPart) !== null;
    }

    public function getDomainId(string $domain): ?string
    {
        if ($this->domainIdCache !== null) return $this->domainIdCache;

        $result = $this->cli(['query', 'Domain']);
        if (!$result['ok']) return null;

        foreach (preg_split('/\R/', trim($result['stdout'])) as $line) {
            if (preg_match('/^([a-z0-9]+)\s+' . preg_quote($domain, '/') . '\s/i', $line, $m)) {
                return $this->domainIdCache = $m[1];
            }
        }
        return null;
    }

    public function generatePassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#%';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    private function cli(array $args): array
    {
        $binary = config('services.stalwart.cli_path', '/usr/local/bin/stalwart-cli');
        $baseArgs = [
            $binary,
            '--url',      config('services.stalwart.url', 'https://mail.ibereality.com'),
            '--user',     config('services.stalwart.admin_user'),
            '--password', config('services.stalwart.admin_password'),
            '--no-color',
        ];

        // Stalwart CLI butuh writable HOME utk cache config. www-data default
        // HOME-nya /var/www yang tidak writable, jadi force ke /tmp.
        $process = new Process(
            array_merge($baseArgs, $args),
            null,
            ['HOME' => '/tmp', 'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'],
        );
        $process->setTimeout(20);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['ok' => false, 'stdout' => '', 'error' => $e->getMessage()];
        }

        return [
            'ok'     => $process->isSuccessful(),
            'stdout' => $process->getOutput(),
            'error'  => trim($process->getErrorOutput() ?: $process->getOutput()),
        ];
    }
}
