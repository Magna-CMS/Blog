<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SSRF-hardened outbound fetcher for the editor's link-preview and RSS tools.
 *
 * Every hop is validated AND the connection is pinned to the exact public IPs
 * the host resolved to at validation time (via CURLOPT_RESOLVE), so the HTTP
 * client cannot re-resolve the name to a private address in the window between
 * the check and the connect (DNS rebinding). Redirects are followed manually so
 * each new location goes through the same validate-then-pin cycle — auto-follow
 * would let the client resolve the redirect target itself, unvalidated.
 *
 * Response size is capped by the caller; time is capped per request. Blocked or
 * failed fetches return null (never leak the reason to the tool UI).
 */
final class SafeHttpFetcher
{
    /** Redirect hops to follow before giving up. */
    private const MAX_HOPS = 4;

    public function __construct(private readonly UrlGuard $guard) {}

    /**
     * Fetch up to $maxBytes of body from a public URL, or null when the URL (or
     * any redirect in the chain) is not a public http/https address.
     */
    public function fetch(string $url, int $timeoutSeconds, int $maxBytes, string $userAgent): ?string
    {
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $ips = $this->guard->resolvedPublicIps($url);
            if ($ips === []) {
                return null;
            }

            $parts = parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
            $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));

            try {
                $response = Http::timeout($timeoutSeconds)
                    ->withHeaders(['User-Agent' => $userAgent])
                    ->withOptions([
                        // Follow redirects ourselves so each hop is re-validated.
                        'allow_redirects' => false,
                        // Pin the hostname to the just-validated public IP(s); curl
                        // connects to these and never re-resolves the name.
                        'curl' => [
                            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, implode(',', $ips))],
                        ],
                    ])
                    ->get($url);
            } catch (Throwable) {
                return null;
            }

            if ($response->redirect()) {
                $location = (string) $response->header('Location');
                $next = $this->resolveLocation($url, $location);
                if ($next === null) {
                    return null;
                }
                $url = $next;

                continue;
            }

            if (! $response->successful()) {
                return null;
            }

            return substr($response->body(), 0, $maxBytes);
        }

        // Too many redirects.
        return null;
    }

    /**
     * Resolve a (possibly relative) redirect Location against the current URL,
     * returning an absolute http/https URL or null when it cannot be made into one.
     */
    private function resolveLocation(string $base, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        // Absolute URL: keep as-is (the next loop re-validates its scheme/host).
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $baseParts = parse_url($base);
        $scheme = $baseParts['scheme'] ?? null;
        $host = $baseParts['host'] ?? null;
        if ($scheme === null || $host === null) {
            return null;
        }

        $authority = $scheme.'://'.$host.(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        // Root-relative path.
        if (str_starts_with($location, '/')) {
            return $authority.$location;
        }

        // Relative to the current directory.
        $path = $baseParts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $authority.$dir.$location;
    }
}
