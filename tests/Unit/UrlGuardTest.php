<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Tests\Unit;

use MagnaCms\Blog\Support\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlGuardTest extends TestCase
{
    private UrlGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new UrlGuard;
    }

    #[DataProvider('blockedUrls')]
    public function test_it_blocks_unsafe_urls(string $url): void
    {
        $this->assertFalse($this->guard->isPublicHttpUrl($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blockedUrls(): array
    {
        return [
            // IPv4 special-purpose ranges (IANA) — none are publicly routable.
            'this-network 0/8' => ['http://0.1.2.3/x'],
            'private 10/8' => ['http://10.0.0.5/x'],
            'cgnat 100.64/10' => ['http://100.64.0.1/x'],
            'loopback 127/8' => ['http://127.0.0.1/x'],
            'link-local 169.254/16' => ['http://169.254.169.254/latest/meta-data'],
            'private 172.16/12' => ['http://172.16.0.1/x'],
            'proto 192.0.0/24' => ['http://192.0.0.1/x'],
            'test-net-1 192.0.2/24' => ['http://192.0.2.5/x'],
            '6to4-relay 192.88.99/24' => ['http://192.88.99.1/x'],
            'private 192.168/16' => ['http://192.168.1.1/x'],
            'benchmark 198.18/15' => ['http://198.18.0.1/x'],
            'test-net-2 198.51.100/24' => ['http://198.51.100.1/x'],
            'test-net-3 203.0.113/24' => ['http://203.0.113.1/x'],
            'multicast 224/4' => ['http://224.0.0.1/x'],
            'reserved 240/4' => ['http://240.0.0.1/x'],
            'broadcast' => ['http://255.255.255.255/x'],

            // IPv6 special-purpose ranges.
            'unspecified ::/128' => ['http://[::]/x'],
            'loopback ::1' => ['http://[::1]/x'],
            'discard 100::/64' => ['http://[100::1]/x'],
            'doc 2001:db8::/32' => ['http://[2001:db8::1]/x'],
            'teredo 2001::/32' => ['http://[2001:0:0:0:0:0:0:1]/x'],
            '6to4 2002::/16' => ['http://[2002:0a00:0001::]/x'],
            'ula fc00::/7' => ['http://[fc00::1]/x'],
            'ula fd00' => ['http://[fd00::1]/x'],
            'link-local fe80::/10' => ['http://[fe80::1]/x'],
            'multicast ff00::/8' => ['http://[ff02::1]/x'],

            // IPv4 embedded in IPv6 — the embedded address decides.
            'mapped metadata' => ['http://[::ffff:169.254.169.254]/x'],
            'mapped private' => ['http://[::ffff:10.0.0.1]/x'],
            'nat64 metadata' => ['http://[64:ff9b::a9fe:a9fe]/latest/meta-data'],

            // Non-http(s) schemes and malformed hosts.
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://example.com/x'],
            'no host' => ['http:///x'],
        ];
    }

    #[DataProvider('allowedUrls')]
    public function test_it_allows_public_http_urls(string $url): void
    {
        $this->assertTrue($this->guard->isPublicHttpUrl($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedUrls(): array
    {
        return [
            'public v4' => ['http://8.8.8.8/'],
            'public https' => ['https://93.184.216.34/'],
            // Boundaries: the addresses immediately outside CGNAT 100.64.0.0/10.
            'just below cgnat' => ['http://100.63.255.255/'],
            'just above cgnat' => ['http://100.128.0.1/'],
            // Public IPv6 literals must still resolve.
            'public v6 cloudflare' => ['http://[2606:4700:4700::1111]/'],
            'public v6 google dns' => ['http://[2001:4860:4860::8888]/'],
            // A mapped PUBLIC IPv4 stays reachable (only the embedded v4 matters).
            'mapped public v4' => ['http://[::ffff:8.8.8.8]/'],
            'nat64 public v4' => ['http://[64:ff9b::0808:0808]/'],
        ];
    }
}
