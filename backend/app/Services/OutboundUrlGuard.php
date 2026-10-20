<?php

namespace App\Services;

use RuntimeException;

/**
 * Resolve and pin outbound HTTP destinations before a request is sent.
 *
 * This is deliberately fail-closed: a webhook is an administrator-controlled
 * integration, not permission to reach the host/container private network.
 */
class OutboundUrlGuard
{
    /** @return array{host: string, ip: string, port: int} */
    public function resolvePublic(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Webhook URL tidak valid.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Webhook URL tidak boleh memuat credential.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolveHost($host);

        if ($ips === []) {
            throw new RuntimeException('Hostname webhook tidak dapat di-resolve.');
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new RuntimeException('Webhook tidak boleh mengarah ke jaringan privat atau reserved.');
            }
        }

        return ['host' => $host, 'ip' => $ips[0], 'port' => $port];
    }

    /** @return list<string> */
    private function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
