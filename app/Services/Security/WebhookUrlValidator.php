<?php

declare(strict_types=1);

namespace App\Services\Security;

class WebhookUrlValidator
{
    /**
     * Hostnames and domain suffixes that are always disallowed for outbound webhooks.
     *
     * @var array<int, string>
     */
    private const array BLOCKED_HOST_SUFFIXES = [
        '.localhost',
        '.local',
        '.internal',
        '.localdomain',
    ];

    /**
     * Determine whether the provided webhook URL is safe from SSRF attacks.
     */
    public function isSafe(string $url): bool
    {
        $parsed = parse_url(trim($url));
        if (! is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        $allowedSchemes = app()->isProduction() ? ['https'] : ['http', 'https'];
        if (! in_array($scheme, $allowedSchemes, true)) {
            return false;
        }

        $host = strtolower(trim($parsed['host'], "[] \t\n\r\0\x0B."));
        if ($host === '' || $host === 'localhost') {
            return false;
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        // Literal IPv4 or IPv6 address
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host);
        }

        $resolvedIps = $this->resolveHostIps($host);
        if ($resolvedIps === []) {
            // Fail closed in production if DNS cannot be resolved; allow offline test domains in non-production.
            return ! app()->isProduction();
        }

        foreach ($resolvedIps as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verify that an IP address is publicly routable (not private, loopback, or reserved).
     */
    public function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Resolve all A and AAAA DNS records for a hostname.
     *
     * @return array<int, string>
     */
    protected function resolveHostIps(string $host): array
    {
        $ips = [];

        $ipv4Records = @gethostbynamel($host);
        if (is_array($ipv4Records)) {
            foreach ($ipv4Records as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $ip;
                }
            }
        }

        if (function_exists('dns_get_record')) {
            $aaaaRecords = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaaRecords)) {
                foreach ($aaaaRecords as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
