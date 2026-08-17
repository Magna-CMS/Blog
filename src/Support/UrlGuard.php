<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

/**
 * Guards against SSRF when the server fetches a user-supplied URL (link preview).
 * A URL is only allowed when it is http/https AND every IP its host resolves to
 * is a public address — private, loopback, link-local and reserved ranges are
 * rejected for both IPv4 and IPv6.
 */
final class UrlGuard
{
    public function isPublicHttpUrl(string $url): bool
    {
        return $this->resolvedPublicIps($url) !== [];
    }

    /**
     * Resolve an http/https URL's host and return every IP it maps to — but only
     * when ALL of them are public. If the URL is malformed, non-http(s), or any
     * resolved address is private / reserved / loopback / link-local (v4 or v6),
     * an empty list is returned. Callers pin the connection to exactly these IPs
     * (CURLOPT_RESOLVE) so DNS cannot be re-resolved to a private address between
     * this check and the actual request (closes the rebinding window).
     *
     * @return list<string>
     */
    public function resolvedPublicIps(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return [];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return [];
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return [];
        }

        $ips = $this->resolve($host);
        if ($ips === []) {
            return [];
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return [];
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        // A bracketed IPv6 literal (http://[::1]/) arrives as "[::1]"; unwrap it
        // so a literal address is validated as an IP rather than mistaken for an
        // (unresolvable) hostname that would slip through unchecked.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $v4 = gethostbynamel($host);
        $v4 = is_array($v4) ? $v4 : [];

        $records = @dns_get_record($host, DNS_AAAA);
        $v6 = is_array($records) ? array_values(array_filter(array_map(
            static fn (array $r): string => (string) ($r['ipv6'] ?? ''),
            $records,
        ))) : [];

        return array_values(array_filter([...$v4, ...$v6]));
    }

    /**
     * IANA/RFC special-purpose IPv4 ranges that must never be fetched (SSRF):
     * "this network", private, CGNAT, loopback, link-local, protocol assignments,
     * documentation, 6to4 relay anycast, benchmarking, multicast, reserved, and
     * limited broadcast. Each entry is [network, prefix-bits].
     *
     * @var list<array{0: string, 1: int}>
     */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
        ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
        ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
        ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4], ['255.255.255.255', 32],
    ];

    /**
     * Special-purpose IPv6 ranges that must never be fetched: unspecified,
     * loopback, discard-only, documentation, Teredo, 6to4, unique-local,
     * link-local and multicast. IPv4-embedding ranges (mapped, NAT64) are handled
     * separately so their embedded v4 is re-validated rather than blanket-blocked.
     *
     * @var list<array{0: string, 1: int}>
     */
    private const BLOCKED_V6 = [
        ['::', 128], ['::1', 128], ['100::', 64], ['2001:db8::', 32],
        ['2001::', 32], ['2002::', 16], ['fc00::', 7], ['fe80::', 10], ['ff00::', 8],
    ];

    /**
     * Whether an IP is a globally-routable public address. Uses an explicit
     * special-purpose blocklist rather than PHP's FILTER_FLAG_NO_PRIV/RES_RANGE,
     * whose coverage is incomplete (it passes CGNAT 100.64.0.0/10, multicast, the
     * TEST-NET ranges and several IPv6 transition ranges as "public"). An IPv4
     * address embedded in IPv6 (mapped ::ffff:0:0/96, NAT64 64:ff9b::/96) is
     * canonicalised and re-checked as IPv4, so a crafted AAAA record cannot
     * smuggle a private / metadata IPv4 past the guard on a translating network.
     */
    private function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            foreach (self::BLOCKED_V4 as [$network, $bits]) {
                if ($this->inRange($packed, (string) inet_pton($network), $bits)) {
                    return false;
                }
            }

            return true;
        }

        // IPv4-in-IPv6: canonicalise the embedded v4 and re-check it as IPv4.
        if ($this->inRange($packed, (string) inet_pton('::ffff:0.0.0.0'), 96)
            || $this->inRange($packed, (string) inet_pton('64:ff9b::'), 96)) {
            $embedded = inet_ntop(substr($packed, 12));

            return $embedded !== false && $this->isPublicIp($embedded);
        }

        foreach (self::BLOCKED_V6 as [$network, $bits]) {
            if ($this->inRange($packed, (string) inet_pton($network), $bits)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when the packed address falls inside the packed network for the given
     * prefix length. Compares whole bytes, then the partial leading byte under a
     * bitmask so any prefix length (not only byte boundaries) is exact.
     */
    private function inRange(string $ip, string $network, int $bits): bool
    {
        if ($network === '' || strlen($ip) !== strlen($network)) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && strncmp($ip, $network, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($ip[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
