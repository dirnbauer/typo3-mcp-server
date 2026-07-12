<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Exception\ValidationException;

/**
 * Resolves every address family for an outbound URL and pins cURL to one of
 * the validated addresses. This closes both mixed A/AAAA SSRF bypasses and
 * the DNS-rebinding gap between validation and connection setup.
 */
final readonly class OutboundUrlGuardService
{
    /** @param (\Closure(string): list<string>)|null $dnsResolver Test seam */
    public function __construct(
        private ?\Closure $dnsResolver = null,
    ) {}

    /**
     * @return string|null CURLOPT_RESOLVE entry, or null for an IP-literal URL
     */
    public function assertPublicAndCreateCurlResolveEntry(string $url): ?string
    {
        $parsed = parse_url($url);
        $scheme = is_array($parsed) && is_string($parsed['scheme'] ?? null)
            ? strtolower($parsed['scheme'])
            : '';
        $host = is_array($parsed) && is_string($parsed['host'] ?? null)
            ? trim($parsed['host'], '[]')
            : '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new ValidationException(['Invalid outbound HTTP(S) URL.']);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublicAddress($host);
            return null;
        }

        $addresses = $this->resolveAllAddresses($host);
        if ($addresses === []) {
            throw new ValidationException(['Could not resolve outbound hostname: ' . $host]);
        }
        foreach ($addresses as $address) {
            $this->assertPublicAddress($address);
        }

        $port = is_int($parsed['port'] ?? null)
            ? $parsed['port']
            : ($scheme === 'https' ? 443 : 80);
        $pinnedAddress = $addresses[0];
        if (filter_var($pinnedAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $pinnedAddress = '[' . $pinnedAddress . ']';
        }

        return sprintf('%s:%d:%s', $host, $port, $pinnedAddress);
    }

    /** @return list<string> */
    private function resolveAllAddresses(string $host): array
    {
        if ($this->dnsResolver !== null) {
            return ($this->dnsResolver)($host);
        }

        $addresses = [];

        $ipv4 = gethostbynamel($host);
        if (is_array($ipv4)) {
            foreach ($ipv4 as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $addresses[] = $address;
                }
            }
        }

        $ipv6Records = @dns_get_record($host, DNS_AAAA);
        if (is_array($ipv6Records)) {
            foreach ($ipv6Records as $record) {
                $address = $record['ipv6'] ?? null;
                if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    private function assertPublicAddress(string $address): void
    {
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            throw new ValidationException([
                'Outbound URL resolves to a private or reserved network address.',
            ]);
        }
    }
}
