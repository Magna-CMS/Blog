<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Support\SafeHttpFetcher;
use MagnaCms\Blog\Support\UrlGuard;

uses(PluginTestCase::class);

function fetcher(): SafeHttpFetcher
{
    return new SafeHttpFetcher(new UrlGuard);
}

// ---- UrlGuard::resolvedPublicIps (validation source of truth) --------------

it('returns no IPs for private, reserved and non-http targets', function (string $url): void {
    expect((new UrlGuard)->resolvedPublicIps($url))->toBe([]);
})->with([
    'loopback v4' => ['http://127.0.0.1/x'],
    'loopback v6' => ['http://[::1]/x'],
    'private 10' => ['http://10.0.0.5/x'],
    'private 192' => ['http://192.168.1.1/x'],
    'private 172' => ['http://172.16.0.1/x'],
    'cgnat 100.64/10' => ['http://100.64.0.1/x'],
    'benchmark 198.18/15' => ['http://198.18.0.1/x'],
    'multicast v4' => ['http://224.0.0.1/x'],
    'link-local' => ['http://169.254.169.254/latest/meta-data'],
    'multicast v6' => ['http://[ff02::1]/x'],
    'nat64 metadata' => ['http://[64:ff9b::a9fe:a9fe]/latest/meta-data'],
    'mapped metadata' => ['http://[::ffff:169.254.169.254]/x'],
    'file scheme' => ['file:///etc/passwd'],
    'ftp scheme' => ['ftp://8.8.8.8/x'],
    'no host' => ['http:///x'],
]);

it('returns the pinned public IP for a public literal address', function (): void {
    expect((new UrlGuard)->resolvedPublicIps('http://8.8.8.8/'))->toBe(['8.8.8.8']);
});

it('allows a public IPv6 literal and a mapped-public IPv4', function (): void {
    expect((new UrlGuard)->resolvedPublicIps('http://[2606:4700:4700::1111]/'))
        ->toBe(['2606:4700:4700::1111']);
});

// ---- SafeHttpFetcher redirect + pin behaviour ------------------------------

it('fetches a public URL and caps the body length', function (): void {
    Http::fake(['*' => Http::response(str_repeat('A', 50), 200)]);

    $body = fetcher()->fetch('http://93.184.216.34/', timeoutSeconds: 5, maxBytes: 10, userAgent: 'test');

    expect($body)->toBe(str_repeat('A', 10));
});

it('blocks a redirect that points at a private address', function (): void {
    // First hop is a public host that 302-redirects to loopback. The fetcher must
    // re-validate the Location and refuse to follow it.
    Http::fake([
        '*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/evil']),
    ]);

    $body = fetcher()->fetch('http://93.184.216.34/', timeoutSeconds: 5, maxBytes: 1000, userAgent: 'test');

    expect($body)->toBeNull();
});

it('returns null immediately for a private initial URL without making a request', function (): void {
    Http::fake();

    $body = fetcher()->fetch('http://169.254.169.254/latest/meta-data', timeoutSeconds: 5, maxBytes: 1000, userAgent: 'test');

    expect($body)->toBeNull();
    Http::assertNothingSent();
});

it('never sends a request to a CGNAT / NAT64 target through the fetch path', function (string $url): void {
    Http::fake();

    $body = fetcher()->fetch($url, timeoutSeconds: 5, maxBytes: 1000, userAgent: 'test');

    expect($body)->toBeNull();
    Http::assertNothingSent();
})->with([
    'cgnat literal' => ['http://100.64.0.1/x'],
    'multicast literal' => ['http://224.0.0.1/x'],
    'nat64 metadata' => ['http://[64:ff9b::a9fe:a9fe]/latest/meta-data'],
]);
