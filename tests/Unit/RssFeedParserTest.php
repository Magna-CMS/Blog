<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Tests\Unit;

use MagnaCms\Blog\Http\Controllers\EditorToolController;
use MagnaCms\Blog\Support\SafeHttpFetcher;
use MagnaCms\Blog\Support\UrlGuard;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit-tests the RSS/Atom parser in isolation (no HTTP, no Laravel container).
 * The network fetch + SSRF guard are separate concerns; here we only prove the
 * XML → items mapping and that external entities are never resolved.
 */
final class RssFeedParserTest extends TestCase
{
    private function parse(string $xml): mixed
    {
        $method = new ReflectionMethod(EditorToolController::class, 'parseFeed');
        $method->setAccessible(true);

        // parseFeed is a pure parser; the controller's fetcher dependency is
        // unused here but required by the constructor.
        $controller = new EditorToolController(new SafeHttpFetcher(new UrlGuard));

        return $method->invoke($controller, $xml);
    }

    public function test_it_parses_rss_two_point_zero(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0"?>
        <rss version="2.0"><channel>
            <title>Example Blog</title>
            <item><title>First</title><link>https://example.test/1</link><pubDate>Mon, 01 Jan 2026 00:00:00 GMT</pubDate></item>
            <item><title>Second</title><link>https://example.test/2</link><pubDate>Tue, 02 Jan 2026 00:00:00 GMT</pubDate></item>
        </channel></rss>
        XML;

        $result = $this->parse($xml);

        $this->assertSame('Example Blog', $result['feed']['title']);
        $this->assertCount(2, $result['items']);
        $this->assertSame('First', $result['items'][0]['title']);
        $this->assertSame('https://example.test/1', $result['items'][0]['link']);
        $this->assertStringContainsString('01 Jan 2026', $result['items'][0]['date']);
    }

    public function test_it_parses_atom_and_prefers_the_alternate_link(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom">
            <title>Atom Feed</title>
            <entry>
                <title>Hello</title>
                <link rel="self" href="https://example.test/self"/>
                <link rel="alternate" href="https://example.test/post"/>
                <updated>2026-01-01T00:00:00Z</updated>
            </entry>
        </feed>
        XML;

        $result = $this->parse($xml);

        $this->assertSame('Atom Feed', $result['feed']['title']);
        $this->assertSame('Hello', $result['items'][0]['title']);
        $this->assertSame('https://example.test/post', $result['items'][0]['link']);
    }

    public function test_it_does_not_resolve_external_entities(): void
    {
        // A classic XXE payload: if entities were resolved, the item title would
        // contain the file contents. LIBXML_NONET + no entity substitution keep
        // it inert — the title stays empty (or literally unexpanded).
        $xml = <<<'XML'
        <?xml version="1.0"?>
        <!DOCTYPE rss [ <!ENTITY xxe SYSTEM "file:///etc/passwd"> ]>
        <rss version="2.0"><channel>
            <title>Feed</title>
            <item><title>&xxe;</title><link>https://example.test/1</link></item>
        </channel></rss>
        XML;

        $result = $this->parse($xml);

        // Either parsing fails (null) or the entity is not expanded to file data.
        if ($result !== null) {
            $this->assertStringNotContainsString('root:', $result['items'][0]['title'] ?? '');
        }
        $this->assertTrue(true);
    }

    public function test_it_returns_null_for_non_feed_xml(): void
    {
        $this->assertNull($this->parse('<html><body>not a feed</body></html>'));
    }
}
